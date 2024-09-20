<?php

namespace BomberNet\Laratable\Http\Controllers;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use BomberNet\Reflector\MethodsTrait;
use Illuminate\Support\Facades\Schema;
use BomberNet\LaravelExtensions\Support\Str;
use BomberNet\LaravelExtensions\Models\Model;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Auth\Access\Authorizable;
use BomberNet\Laratable\Events\ResponseReadyEvent;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use function count;
use function in_array;
use function array_key_exists;

abstract class AbstractTableController
	{
		use AuthorizesRequests,ValidatesRequests;
		use MethodsTrait;
		
		protected const DEFAULT_PER_PAGE=1;
		protected const DEFAULT_PAGE=1;
		/** @var class-string $modelClass */
		protected string $modelClass;
		protected string $authorizeAbility='viewAny';
		
		public function __invoke (Request $request):array
			{
				$this->authorize ($this->authorizeAbility,$this->modelClass);
				$this->validate ($request,$this->requestRules ($request));
				$controls=$this->controlsFilter ($request);
				/** @var Model $model */
				$model=app ($this->modelClass);
				$query=method_exists ($this,'query')?$this->query ($request):$model::query ();
				$counts=[];
				$this->tableFilterByUserRights ($query,$request);
				$this->tableBind ($query,$request);
				$counts['total']=$query->count ();
				$this->tableFilter ($query,$request);
				$this->tableFreeSearch ($query,$request,$request->get ('freeSearch'));
				$this->tableOrder ($query,$request);
				$counts['filtered']=$query->count ();
				$filteredKeys=$query->pluck ($model->getTable ().'.'.$model->getKeyName ());
				$paginate=$this->tablePaginate ($query,$request);
				$rows=$this->tableRows ($request,$paginate->items ());
				$pages=['total'=>$paginate->lastPage ()];
				$response=compact ('controls','rows','filteredKeys','pages','counts');
				ResponseReadyEvent::dispatch ($this->modelClass,$request,$response);
				return $response;
			}
		
		protected function requestRules (Request $request):array
			{
				return
					[
						'binds'=>'sometimes|present|array',
						'controls'=>'sometimes|present|array',
						'actions'=>'sometimes|present|array',
						'filter'=>'sometimes|present|array',
						'invertedFilter'=>'sometimes|present|array',
						'invertedFilter.*'=>Rule::when (count ($request->get ('invertedFilter')),'boolean'),
						'freeSearch'=>'sometimes|present',
						'columns'=>'sometimes|present|array',
						'order'=>'sometimes|present|array',
						'order.*'=>Rule::when (count ($request->get ('order')),'numeric|integer'),
						'page'=>'sometimes|required|numeric|integer|min:1',
						'perPage'=>'sometimes|required|numeric|integer|min:1',
					];
			}
		
		protected function controlsFilter (Request $request):array
			{
				/** @var Authorizable $user */
				$user=$request->user ();
				return array_values (array_filter ($request->get ('controls',[]),function (string $control) use ($user)
					{
						$method=Str::camel ("control_$control");
						if (method_exists ($this,$method)) return $this->{$method} ($user);
						else return $user->can ($control,$this->modelClass);
					}));
			}
		
		protected function tableFilterByUserRights (Builder $query,Request $request):void
			{
			}
		
		protected function tableFilter (Builder $query,Request $request):void
			{
				if (count ($filter=$request->get ('filter',[])))
					{
						foreach ($filter as $key=>$cond)
							{
								if ($cond!==null)
									{
										$method=Str::camel ("filter_$key");
										if (method_exists ($this,$method)) $this->{$method} ($query,$cond,$request->input ("invertedFilter.$key",false));
											else
												{
													$not=$request->input ("invertedFilter.$key")?'not':'';
													$query->whereRaw ("concat ($key,'') $not like '%$cond%'");
												}
									}
							}
					}
			}
		
		protected function tableFreeSearch (Builder $query,Request $request,?string $search):void
			{
				if ($search) $query->where (function (Builder $query) use ($request,$search)
					{
						$columns=Schema::getColumnListing (app ($this->modelClass)->getTable ());
						foreach ($request->get ('columns',[]) as $column)
							{
								$method=Str::camel ("free_search_$column");
								if (method_exists ($this,$method)) $query->orWhere ($this->{$method} ($query,$search));
									elseif (in_array ($column,$columns,true)) $query->orWhereRaw ("concat ($column,'') like '%$search%'");
							}
					});
			}
		
		protected function tableBind (Builder $query,Request $request):void
			{
				if (count ($binds=$request->get ('binds',[])))
					{
						foreach ($binds as $bind=>$id)
							{
								if ($id!==null)
									{
										$method=Str::camel ("bind_$bind");
										if (method_exists ($this,$method)) $this->{$method} ($query,$id);
										else
											{
												/** @var Model $class */
												$class=Str::of ($bind)->studly ()->start ('App\\Models\\')->toString ();
												$model=$class::find ($id);
												$this->authorize ('view',$model);
												$query->where ("{$bind}_id",$id);
											}
									}
							}
					}
			}
		
		protected function tableOrder (Builder $query,Request $request):void
			{
				if (count ($order=$request->get ('order',[])))
					{
						foreach ($order as $key=>$direction)
							{
								$direction=['desc',null,'asc'][sign ($direction)+1];
								if (!$direction) continue;
								$method=Str::camel ("order_$key");
								if (method_exists ($this,$method)) $this->{$method} ($query,$direction);
								else $query->orderBy ($key,$direction);
							}
					}
			}
		
		protected function tablePaginate (Builder $query,Request $request):LengthAwarePaginator
			{
				return $query->paginate ($request->get ('perPage',self::DEFAULT_PER_PAGE));
			}
		
		protected function tableRows (Request $request,array $rows):array
			{
				$rows=array_map (fn (Model $model) => $this->tableColumns ($model,$request),$rows);
				if (in_array ('#',$request->get ('columns',[]),true))
					{
						$shift=($request->get ('page',self::DEFAULT_PAGE)-1)*$request->get ('perPage',self::DEFAULT_PER_PAGE);
						foreach ($rows as $i=>$row) $rows[$i]['row']['#']=$shift+$i+1;
					}
				return $rows;
			}
		
		protected function tableColumns (Model $model,Request $request):array
			{
				if (count ($columns=$request->get ('columns',[])))
					{
						$row=[];
						foreach ($columns as $column)
							{
								$method=Str::camel ("column_$column");
								switch (true)
									{
										case $column==='#':
											break;
										case !method_exists ($this,$method):
											$row[$column]=$model->{$column};
											break;
										case $this->methodParamCount ($method)<2:
											$row[$column]=$this->{$method} ($model);
											break;
										default:
											$row[$column]=$this->{$method} ($model,$model->{$column});
									}
							}
					}
				else $row=$model->toArray ();
				$primary=$model->getKeyName ();
				if (!array_key_exists ($primary,$row)) $row[$primary]=$model->{$primary};
				$actions=$this->actionsFilter ($model,$request);
				return compact ('row','actions');
			}
		
		protected function actionsFilter (Model $model,Request $request):array
			{
				/** @var Authorizable $user */
				$user=$request->user ();
				return array_values (array_filter ($request->get ('actions',[]),function (string $action) use ($model,$user)
					{
						$method=Str::camel ("action_$action");
						if (method_exists ($this,$method)) return $this->{$method} ($user,$model);
						else return $user->can ($action,$model);
					}));
			}
	}
