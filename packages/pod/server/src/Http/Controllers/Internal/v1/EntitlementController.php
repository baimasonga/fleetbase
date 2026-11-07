<?php

namespace Fleetbase\Pod\Http\Controllers\Internal\v1;

use Fleetbase\Pod\Services\EntitlementService;
use Illuminate\Http\Request;

/**
 *   GET  programmes/{id}/items                    the programme's inputs
 *   PUT  programmes/{id}/items                    replace them: {"items": [...]}
 *   GET  programmes/{id}/entitlement-rules
 *   PUT  programmes/{id}/entitlement-rules        {"rules": [...]}
 *   POST programmes/{id}/entitlements/generate    {"dry_run": true} to preview
 *   GET  programmes/{id}/entitlements/summary     ?level=2 (district) | 3 (chiefdom)
 */
class EntitlementController extends PodController
{
    public function __construct(private EntitlementService $entitlements)
    {
    }

    public function items(string $id)
    {
        return response()->json(['items' => $this->programme($id)->items()->get()]);
    }

    public function setItems(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $items     = $request->validate(['items' => ['present', 'array']])['items'];

        try {
            $saved = $this->entitlements->setItems($programme, $items);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['items' => $saved]);
    }

    public function rules(string $id)
    {
        return response()->json(['rules' => $this->programme($id)->entitlementRules()]);
    }

    public function setRules(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $rules     = $request->validate(['rules' => ['present', 'array']])['rules'];

        try {
            $saved = $this->entitlements->setRules($programme, $rules);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['rules' => $saved]);
    }

    public function generate(Request $request, string $id)
    {
        $programme = $this->programme($id);

        try {
            $result = $this->entitlements->generate($programme, $request->boolean('dry_run'));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json($result);
    }

    public function summary(Request $request, string $id)
    {
        $programme = $this->programme($id);
        $level     = (int) $request->input('level', 2);

        try {
            $rows = $this->entitlements->summary($programme, $level);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['level' => $level, 'rows' => $rows]);
    }
}
