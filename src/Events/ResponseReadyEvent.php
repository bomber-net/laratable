<?php

namespace BomberNet\Laratable\Events;

use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class ResponseReadyEvent
	{
		use Dispatchable,InteractsWithSockets,SerializesModels;
		
		public string $modelClass;
		public Request $request;
		public function __construct (string $modelClass,Request $request,array $response)
			{
				$this->modelClass=$modelClass;
				$this->request=$request;
				$this->response=$response;
			}
		
		public array $response;
	}
