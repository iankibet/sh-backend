<?php
namespace Iankibet\Shbackend\App\GraphQl;

use Illuminate\Support\Str;

class GraphQlRepository
{
    public function getQueryFromDocument($document){
        $document = json_decode(json_encode($document));
        $sets = [
        ];
        foreach ($document->definitions as $definition){
            if($definition->operation == 'query'){
                if($definition->selectionSet->kind == 'SelectionSet'){
                    foreach ($definition->selectionSet->selections as $selection){
                        if($selection->kind == 'Field'){
                            $key = $selection->name->value;
                            $fields = $this->getSelectionSetFields($selection->selectionSet);
                            $results = $this->getModelQuery($key)->model->select($fields['fields']);
                            if($selection->arguments) {
                                $results = $this->getSelectionArguments($results,$selection);
                            }
                            if($fields['sets']){
                                foreach ($fields['sets'] as $model=>$val){
                                    $keys = $val['keys'];
                                    $withString = 'id,'.implode(',',$keys);
                                    $selectKeys = explode(',',$withString);
                                    $selection = $val['selection'];
                                    $results = $results->with(['user'=>function($query) use($selection,$selectKeys){
                                        return $this->getSelectionArguments($query,$selection)->select($selectKeys);
                                    }]);
                                }
                            }
                            $sets[$key] = $results;
                        }
                    }
                }
            }
        }
        return $sets;
    }

    public function getSelectionArguments($query, $selection){
        $queryArguments = [];
//        dd($selection->arguments);
        foreach ($selection->arguments as $argument){

            $operator = 'like';
            $equalTypes = ['IntValue','BooleanValue'];
            if(in_array($argument->value->kind,$equalTypes)){
                $operator = '=';
            }
            if($argument->value->kind == 'ListValue'){
                $values = [];
                foreach ($argument->value->values as $valObject){
                    $values[] = $valObject->value;
                }
                $query = $query->whereIn($argument->name->value,$values);
            } else {
                $value = $argument->value->value;
                $query = $query->where($argument->name->value,$operator,$value);
            }

        }
        return $query;
    }

    function getSelectionSetFields($set){
        $fields = [];
        $sets = [];
        if($set->kind == 'SelectionSet'){
            foreach ($set->selections as $selection){
                if($selection->selectionSet){
                    $sets[$selection->name->value] = [
                        'keys'=>$this->getSelectionSetFields($selection->selectionSet)['fields'],
                        'selection'=>$selection
                    ];
                } else {
                    $fields[] = $selection->name->value;
                }
            }
        }
        return [
            'fields'=>$fields,
            'sets'=>$sets
        ];
    }

    protected function getModelQuery($slug){
        $modelConfig = config('shql.'.$slug);
        if(!$modelConfig){
            throw new \Exception("($slug) Sh model slug does not exist");
        }
        $where = null;
        if(isset($modelConfig['where'])){
            $where = $modelConfig['where'];
        }
        $modelConfig = json_encode($modelConfig);
        $modelConfig = str_replace('{current_user_id}',@request()->user()->id,$modelConfig);
        $modelConfig = str_replace('{user_id}',@request()->user()->id,$modelConfig);
        $modelConfig = json_decode($modelConfig);
        $model = new $modelConfig->model;
        if(isset($modelConfig->where)){
            $model = $model->where($where);
        }
        $modelConfig->model = $model;
        return $modelConfig;
    }
}