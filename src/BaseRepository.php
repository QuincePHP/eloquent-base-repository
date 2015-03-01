<?php namespace Quince\EloquentBaseRepository;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

abstract class BaseRepository {

	/**
	 * Columns that are allowed to be filtered by
	 *
	 * @var array
	 */
	protected $filterable = [];

	/**
	 * Model to work with
	 *
	 * @var Model
	 */
	protected $model;

	/**
	 * Instantiate repository for given model
	 *
	 * @param Model $model
	 */
	public function __construct(Model $model)
	{
		$this->model = $model;
	}

	/**
	 * Create or fetch the first record that match with given data
	 *
	 * @param array $data
	 * @return Model
	 * @throws RepositoryException
	 */
	public function firstOrCreate(Array $data)
	{
		return $this->runThroughTryCatch(function () use ($data) {
			return $this->model->firstOrCreate($data);
		});
	}

	/**
	 * Get an resource by its identifier
	 *
	 * @param int          $id
	 * @param array        $columns
	 * @param string|array $relations
	 * @return Collection
	 * @throws RepositoryException
	 */
	public function findById($id, Array $columns = ['*'], $relations = '')
	{
		return $this->runThroughTryCatch(function () use ($id, $columns, $relations) {
			/** @var \Illuminate\Database\Eloquent\Builder $query */
			$query = $this->model;

			$query->where($this->model->getKeyName(), $id);

			if (is_array($relations)) {
				foreach ($relations as $relation) {
					if (is_string($relation)) {
						$query = $query->with($relation);
					} else {
						throw new RepositoryException("Invalid argument as relation");
					}
				}
			} elseif (!!$relations) {
				$query = $query->with($relations);
			}

			return $query->get($columns);
		});
	}

	/**
	 * Find and get matching results with given queries
	 *
	 * @param array $filters
	 * @param int   $perPage
	 * @param array $columns
	 * @return Model|Collection
	 * @throws RepositoryException
	 */
	public function find(Array $filters, $perPage = 15, $columns = ['*'])
	{
		return $this->runThroughTryCatch(function () use ($filters, $perPage, $columns) {
			if (empty($filters)) {
				return false;
			}

			/** @var \Illuminate\Database\Query\Builder $query */
			$query = $this->model;
			foreach ($this->parseFilters($filters) as $filter) {
				$query = $this->attachWhere($query, $filter);
			}

			return $query->paginate($perPage, $columns);
		});
	}

	/**
	 * Count all or filtered records
	 *
	 * @param array $filters
	 * @return int
	 * @throws RepositoryException
	 */
	public function count(Array $filters = [])
	{
		return $this->runThroughTryCatch(function () use ($filters) {
			/** @var \Illuminate\Database\Query\Builder $query */
			$query = $this->model;

			if (!empty($filters)) {
				foreach ($this->parseFilters($filters) as $filter) {
					$query = $this->attachWhere($query, $filter);
				}
			}

			return $query->count();
		});
	}

	/**
	 * Update or create a record by given id and data
	 *
	 * @param int   $id
	 * @param array $data
	 * @return Model
	 * @throws RepositoryException
	 */
	public function updateById($id, Array $data)
	{
		return $this->runThroughTryCatch(function () use ($id, $data) {
			$data = array_merge($data, [$this->model->getKeyName() => $id]);

			return $this->model->updateOrCreate($data);
		});
	}

	/**
	 * Delete a resource by given identifier
	 *
	 * @param int $id
	 * @return bool
	 * @throws RepositoryException
	 */
	public function deleteById($id)
	{
		return $this->runThroughTryCatch(function () use ($id) {
			return $this->model->find($id)->delete();
		});
	}

	/**
	 * Delete result of given filter
	 *
	 * @param array $filters
	 * @return bool
	 * @throws RepositoryException
	 */
	public function delete(Array $filters)
	{
		return $this->runThroughTryCatch(function () use ($filters) {
			if (empty($filters)) {
				return false;
			}

			/** @var \Illuminate\Database\Query\Builder $query */
			$query = $this->model;
			foreach ($this->parseFilters($filters) as $filter) {
				$query = $this->attachWhere($query, $filter);
			}

			return $query->delete();
		});
	}

	/**
	 * Call repository methods dynamically
	 *
	 * @param string $method
	 * @param array  $args
	 * @return Collection|Model
	 * @throws RepositoryException
	 */
	public function __call($method, $args)
	{
		$prefix = 'findBy';

		if (substr($method, 0, strlen($prefix)) == $prefix) {
			$column = strtolower(substr($method, strlen($prefix)));

			if (in_array($column, $this->filterable)) {
				return $this->runThroughTryCatch(function () use ($column, $args) {
					return $this->find([$column, $args[0]]);
				});
			}

			throw new RepositoryException("Cannot filter the model with column [$column]");
		}

		throw new RepositoryException("Cannot find method [$method] on " . get_class($this));
	}

	/**
	 * Run a closure and catch database related
	 *
	 * @param callable $action
	 * @return mixed
	 * @throws RepositoryException
	 */
	protected function runThroughTryCatch(Closure $action)
	{
		try {
			return $action();
		} catch (QueryException $e) {
			throw new RepositoryException($e->getMessage(), $e->getCode());
		} catch (ModelNotFoundException $e) {
			throw new RepositoryException($e->getMessage(), $e->getCode());
		}
	}

	/**
	 * Run the given closure through database transaction
	 *
	 * @param callable $action
	 * @param array    $param
	 * @return mixed
	 */
	protected function runThroughTransaction(Closure $action, Array $param = [])
	{
		return \DB::transaction(function () use ($action, $param) {
			return call_user_func_array($action, $param);
		});
	}

	/**
	 * Preparing search queries for execution
	 *
	 * @param array $filters
	 * @throws RepositoryException
	 * @return array
	 */
	protected function parseFilters($filters)
	{
		$ret = [];

		if (array_values($filters) !== $filters) {
			throw new RepositoryException('Invalid filter applied');
		} elseif (!is_array($filters[0])) {
			$filters = [$filters];
		}

		foreach ($filters as $filter) {
			list($column, $operator, $value) = array_pad($filter, 3, null);

			// Assuming search query is an `equal to` query.
			// so user might not set the operation and enter only column and its value
			if ($value == null) {
				$value = $operator;
				$operator = '=';
			}

			if (!is_null($column) || !is_null($value)) {
				throw new RepositoryException(
					'One of search queries does not specifed column and its value for searching'
				);
			}

			if (strtoupper($operator) == 'LIKE') {
				$value = $value . '%';
			} elseif (strtoupper($operator) == 'WHEREIN') {
				$value = (is_array($value)) ? $value : (array) $value;
			}

			$ret[] = [
				'column'   => $column,
				'operator' => $operator,
				'value'    => $value
			];
		}

		return $ret;
	}

	/**
	 * Attach a where clause on a builder and return the builder
	 *
	 * @param \Illuminate\Database\Query\Builder $query
	 * @param array                              $filter
	 * @return \Illuminate\Database\Query\Builder
	 */
	protected function attachWhere($query, $filter)
	{
		if (strtoupper($filter['operator']) == 'WHEREIN') {
			$query = $query->whereIn(
				$filter['column'],
				$filter['value']
			);
		} else {
			$query = $query->where(
				$filter['column'],
				$filter['operator'],
				$filter['value']
			);
		}

		return $query;
	}

}
