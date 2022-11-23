<?php

namespace BomberNet\Laratable\Http\Controllers;

use Closure;
use Illuminate\Http\Request;
use BomberNet\Reflector\MethodsTrait;
use BomberNet\ModelPhpdoc\PHPDocTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use BomberNet\LaravelSupportExtensions\Str;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use function count;
use function array_key_exists;

abstract class AbstractTableController
	{
		use AuthorizesRequests,ValidatesRequests;
		use MethodsTrait;
		
		protected string $modelClass;
		
		public function __invoke (Request $request):array
			{
				$this->authorize ('viewAny',$this->modelClass);
				$this->validate ($request,$this->requestRules ());
				$controls=$this->controlsFilter ($request);
				/** @var Model $model */
				$model=$this->modelClass;
				$query=$model::query ();
				$this->tableFilter ($query,$request);
				$this->tableBind ($query,$request);
				$this->tableOrder ($query,$request);
				$filteredKeys=$query->pluck (app ($model)->getKeyName ());
				$paginate=$this->tablePaginate ($query,$request);
				$rows=$this->tableRows ($request,$paginate->items ());
				$pages=['total'=>$paginate->lastPage ()];
				return compact ('controls','rows','filteredKeys','pages');
			}
		
		protected function requestRules ():array
			{
				return
					[
						'binds'=>'present|array',
						'controls'=>'present|array',
						'actions'=>'present|array',
						'filter'=>'present|array',
						'columns'=>'present|array',
						'order'=>
							[
								'present','array',function (string $attribute,array $order,Closure $fail)
								{
									foreach ($order as $key=>$direction) if (!is_intnum ($direction)) $fail ("Order key '$key' must be an integer");
								},
							],
						'page'=>'required|numeric|integer|min:1',
						'perPage'=>'required|numeric|integer|min:1',
					];
			}
		
		protected function controlsFilter (Request $request):array
			{
				/** @var Authorizable $user */
				$user=$request->user ();
				return array_values (array_filter ($request->get ('controls'),function (string $control) use ($user)
					{
						$method=Str::camel ("control_$control");
						if (method_exists ($this,$method)) return $this->{$method} ($user);
						else return $user->can ($control,$this->modelClass);
					}));
			}
		
		protected function tableFilter (Builder $query,Request $request):void
			{
				if (count ($filter=$request->get ('filter')))
					{
						foreach ($filter as $key=>$cond)
							{
								if ($cond!==null)
									{
										$method=Str::camel ("filter_$key");
										if (method_exists ($this,$method)) $this->{$method} ($query,$cond);
										else $query->whereRaw ("concat ($key,'') like '%$cond%'");
									}
							}
					}
			}
		
		protected function tableBind (Builder $query,Request $request):void
			{
				if (count ($binds=$request->get ('binds')))
					{
						foreach ($binds as $bind=>$id)
							{
								if ($id!==null)
									{
										$method=Str::camel ("bind_$bind");
										if (method_exists ($this,$method)) $this->{$method} ($query,$id);
										else
											{
												/** @var PHPDocTrait $class */
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
				if (count ($order=$request->get ('order')))
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
				return $query->paginate ($request->get ('perPage'));
			}
		
		protected function tableRows (Request $request,array $rows):array
			{
				$rows=array_map (fn (Model $model) => $this->tableColumns ($model,$request),$rows);
				if (in_array ('#',$request->get ('columns')))
					{
						$shift=($request->get ('page')-1)*$request->get ('perPage');
						foreach ($rows as $i=>$row) $rows[$i]['row']['#']=$shift+$i+1;
					}
				return $rows;
			}
		
		protected function tableColumns (Model $model,Request $request):array
			{
				if (count ($columns=$request->get ('columns')))
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
					} else $row=$model->toArray ();
				$primary=$model->getKeyName ();
				if (!array_key_exists ($primary,$row)) $row[$primary]=$model->{$primary};
				$actions=$this->actionsFilter ($model,$request);
				return compact ('row','actions');
			}
		
		protected function actionsFilter (Model $model,Request $request):array
			{
				/** @var Authorizable $user */
				$user=$request->user ();
				return array_values (array_filter ($request->get ('actions'),function (string $action) use ($model,$user)
					{
						$method=Str::camel ("action_$action");
						if (method_exists ($this,$method)) return $this->{$method} ($user,$model);
						else return $user->can ($action,$model);
					}));
			}
	}
