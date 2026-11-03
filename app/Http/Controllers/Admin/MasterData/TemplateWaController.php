<?php

namespace App\Http\Controllers\Admin\MasterData;

use App\Http\Controllers\Controller;
use App\Models\ValidationMessage;
use App\Models\WaTemplate;
use App\Support\WhatsappTagihan;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TemplateWaController extends Controller
{
    public string $title = "Master Data";
    public string $mainTitle = "Template Email";
    public string $dataTitle = "Template Email";

    public function index()
    {
        $data["title"] = $this->title;
        $data["mainTitle"] = $this->mainTitle;
        $data["dataTitle"] = $this->dataTitle;
        $data["columnsUrl"] = route("admin.master-data.template-wa.get-column");
        $data["datasUrl"] = route("admin.master-data.template-wa.get-data");
        $data["placeholders"] = WhatsappTagihan::placeholders();

        return view("admin.master_data.template_wa.index", $data);
    }

    public function getColumn()
    {
        return [
            ["data" => null, "name" => "no", "className" => "text-center", "columnType" => "row"],
            ["data" => "kode", "name" => "Kode", "searchable" => true, "orderable" => true],
            ["data" => "nama", "name" => "Nama", "searchable" => true, "orderable" => true],
            ["data" => "template_preview", "name" => "Isi Template", "searchable" => false, "orderable" => false],
            ["data" => "is_active_label", "name" => "Status", "searchable" => false, "orderable" => true],
            [
                "data" => "edit",
                "name" => "",
                "dataVal" => false,
                "columnType" => "button",
                "className" => "text-center",
                "button" => "modal",
                "buttonText" => "Edit",
                "buttonClass" => "btn btn-sm btn-info btn-edit",
                "buttonLink" => "#modal-edit",
                "buttonIcon" => "ri-edit-line me-2",
            ],
            [
                "data" => "delete",
                "name" => "",
                "dataVal" => false,
                "columnType" => "button",
                "className" => "text-center",
                "button" => "modal",
                "buttonText" => "Hapus",
                "buttonClass" => "btn btn-sm btn-danger btn-hapus",
                "buttonLink" => "#modal-delete",
                "buttonIcon" => "ri-delete-bin-line me-2",
            ],
        ];
    }

    public function getData(Request $request)
    {
        $draw = $request->get("draw");
        $start = $request->get("start");
        $rowperpage = $request->get("length");
        $orderArr = $request->get("order", []);
        $columnNameArr = $request->get("columns", []);
        $searchValue = $request->get("search", [])["value"] ?? "";

        $columnName = "id";
        $columnSortOrder = "asc";
        $allowedSort = ["kode", "nama", "is_active_label", "id"];

        if (!empty($orderArr)) {
            $columnIndex = $orderArr[0]["column"] ?? null;
            $requested = $columnNameArr[$columnIndex]["data"] ?? null;
            if ($requested && $requested !== "no" && in_array($requested, $allowedSort, true)) {
                $columnName = $requested === "is_active_label" ? "is_active" : $requested;
                $columnSortOrder = $orderArr[0]["dir"] ?? "asc";
            }
        }

        $searchable = ["kode", "nama", "template"];
        $base = WaTemplate::query();

        $totalRecords = (clone $base)->count();
        $filtered = (clone $base)->when($searchValue !== "", function ($q) use ($searchable, $searchValue) {
            $q->where(function ($inner) use ($searchable, $searchValue) {
                foreach ($searchable as $column) {
                    $inner->orWhere($column, "like", "%" . $searchValue . "%");
                }
            });
        });
        $totalRecordswithFilter = (clone $filtered)->count();

        $records = $filtered
            ->orderBy($columnName, $columnSortOrder)
            ->skip($start)
            ->take($rowperpage)
            ->get()
            ->map(function ($item) {
                $preview = trim((string) $item->template);
                if (mb_strlen($preview) > 80) {
                    $preview = mb_substr($preview, 0, 80) . "...";
                }

                return [
                    "item_id" => $item->id,
                    "kode" => $item->kode,
                    "nama" => $item->nama,
                    "template" => $item->template,
                    "template_preview" => $preview,
                    "is_active" => (int) $item->is_active,
                    "is_active_label" => ((int) $item->is_active) === 1 ? "Aktif" : "Nonaktif",
                    "edit" => true,
                    "delete" => true,
                ];
            })
            ->toArray();

        return response()->json([
            "draw" => intval($draw),
            "recordsTotal" => $totalRecords,
            "recordsFiltered" => $totalRecordswithFilter,
            "data" => $records,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                "kode" => ["required", "string", "max:50", Rule::unique(WaTemplate::class, "kode")],
                "nama" => ["required", "string", "max:100"],
                "template" => ["required", "string"],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        if ($validator->fails()) {
            return response()->json(["message" => $validator->errors()->first(), "errors" => $validator->errors()], 422);
        }

        try {
            DB::connection("DATA_MYSQL")->beginTransaction();
            WaTemplate::create([
                "kode" => trim((string) $request->kode),
                "nama" => trim((string) $request->nama),
                "template" => (string) $request->template,
                "is_active" => $request->boolean("is_active") ? 1 : 0,
            ]);
            DB::connection("DATA_MYSQL")->commit();

            return response()->json(["message" => "Data " . $this->mainTitle . " telah disimpan"]);
        } catch (Exception $e) {
            DB::connection("DATA_MYSQL")->rollBack();
            return response()->json(["message" => "Data " . $this->mainTitle . " gagal disimpan", "error" => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, $id)
    {
        $item = WaTemplate::query()->find($id);
        if (!$item) {
            return response()->json(["message" => "Template Email tidak ditemukan!"], 422);
        }

        $validator = Validator::make(
            $request->all(),
            [
                "kode" => ["required", "string", "max:50", Rule::unique(WaTemplate::class, "kode")->ignore($item->id)],
                "nama" => ["required", "string", "max:100"],
                "template" => ["required", "string"],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        if ($validator->fails()) {
            return response()->json(["message" => $validator->errors()->first(), "errors" => $validator->errors()], 422);
        }

        try {
            DB::connection("DATA_MYSQL")->beginTransaction();
            $item->update([
                "kode" => trim((string) $request->kode),
                "nama" => trim((string) $request->nama),
                "template" => (string) $request->template,
                "is_active" => $request->boolean("is_active") ? 1 : 0,
            ]);
            DB::connection("DATA_MYSQL")->commit();

            return response()->json(["message" => "Data " . $this->mainTitle . " telah diubah"]);
        } catch (Exception $e) {
            DB::connection("DATA_MYSQL")->rollBack();
            return response()->json(["message" => "Data " . $this->mainTitle . " gagal diubah", "error" => $e->getMessage()], 422);
        }
    }

    public function destroy($id)
    {
        $item = WaTemplate::query()->find($id);
        if (!$item) {
            return response()->json(["message" => "Template Email tidak ditemukan!"], 422);
        }

        try {
            DB::connection("DATA_MYSQL")->beginTransaction();
            $item->delete();
            DB::connection("DATA_MYSQL")->commit();

            return response()->json(["message" => "Data " . $this->mainTitle . " telah dihapus"]);
        } catch (Exception $e) {
            DB::connection("DATA_MYSQL")->rollBack();
            return response()->json(["message" => "Data " . $this->mainTitle . " gagal dihapus", "error" => $e->getMessage()], 422);
        }
    }
}
