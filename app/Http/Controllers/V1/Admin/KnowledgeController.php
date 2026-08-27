<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\KnowledgeSave;
use App\Http\Requests\Admin\KnowledgeSort;
use App\Models\Knowledge;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class KnowledgeController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $knowledge = Knowledge::find($request->input('id'));
            // 空结果先判 null（原代码 find()->toArray() 在查不到时直接致命错误）
            if (!$knowledge) abort(500, '知识不存在');
            $knowledge = $knowledge->toArray();
            return response([
                'data' => $knowledge
            ]);
        }
        return response([
            'data' => Knowledge::select(['title', 'id', 'updated_at', 'category', 'show'])
                ->orderBy('sort', 'ASC')
                ->get()
        ]);
    }

    public function getCategory(Request $request)
    {
        return response([
            'data' => array_keys(Knowledge::get()->groupBy('category')->toArray())
        ]);
    }

    public function save(KnowledgeSave $request)
    {
        $params = $request->validated();

        if (!$request->input('id')) {
            if (!Knowledge::create($params)) {
                abort(500, '创建失败');
            }
        } else {
            // find 可能返回 null（null 上调 update() 是 Error，catch(\Exception) 接不住）；判空须在 try 外，
            // 否则 abort 的 HttpException 会被下面的 catch 吞掉、统一报成“保存失败”
            $knowledge = Knowledge::find($request->input('id'));
            if (!$knowledge) abort(500, '知识不存在');
            try {
                $knowledge->update($params);
            } catch (\Exception $e) {
                abort(500, '保存失败');
            }
        }

        return response([
            'data' => true
        ]);
    }

    public function show(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            abort(500, '知识不存在');
        }
        $knowledge->show = $knowledge->show ? 0 : 1;
        if (!$knowledge->save()) {
            abort(500, '保存失败');
        }

        return response([
            'data' => true
        ]);
    }

    public function sort(KnowledgeSort $request)
    {
        DB::beginTransaction();
        try {
            foreach ($request->input('knowledge_ids') as $k => $v) {
                // 条目可能已被删除：跳过（null 上设属性是 Error，catch(\Exception) 接不住）
                $knowledge = Knowledge::find($v);
                if (!$knowledge) continue;
                $knowledge->timestamps = false;
                $knowledge->update(['sort' => $k + 1]);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            abort(500, '保存失败');
        }
        DB::commit();
        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数有误');
        }
        $knowledge = Knowledge::find($request->input('id'));
        if (!$knowledge) {
            abort(500, '知识不存在');
        }
        if (!$knowledge->delete()) {
            abort(500, '删除失败');
        }

        return response([
            'data' => true
        ]);
    }
}
