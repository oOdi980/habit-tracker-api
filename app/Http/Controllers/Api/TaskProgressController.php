<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AddEntryRequest;
use App\Http\Requests\Api\SetProgressRequest;
use App\Services\TaskProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Progres harian sebuah tugas. Check-in habit tercatat/terhapus otomatis mengikuti hasilnya. */
class TaskProgressController extends Controller
{
    public function __construct(private readonly TaskProgressService $progress)
    {
    }

    /** 201 bila ada angka baru tercatat; 200 bila tidak ada yang berubah (ceklis ulang / kirim ulang). */
    public function store(AddEntryRequest $request, int $task): JsonResponse
    {
        $result = $this->progress->addEntry($request->user(), $task, $request->validated());

        return response()->json($result, $result['created'] ? 201 : 200);
    }

    /** "Ubah angka": total hari ini diganti persis. 0 = kosongkan. */
    public function update(SetProgressRequest $request, int $task): JsonResponse
    {
        $weight = $request->validated('weight_g');

        return response()->json($this->progress->setProgress(
            $request->user(),
            $task,
            (int) $request->validated('amount'),
            $weight === null ? null : (int) $weight,
        ));
    }

    /** Kosongkan semua catatan tugas ini hari ini (batal ceklis). */
    public function destroy(Request $request, int $task): JsonResponse
    {
        return response()->json($this->progress->setProgress($request->user(), $task, 0, null));
    }
}
