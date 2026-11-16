<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\HistoryRequest;
use App\Http\Requests\Api\NoteRequest;
use App\Http\Requests\Api\PutTasksRequest;
use App\Services\TaskProgressService;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Daftar tugas sebuah habit (definisi), catatan harian, dan riwayat. */
class HabitTaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskProgressService $progress,
    ) {
    }

    /** Daftar tugas per anggota: 'current' (berlaku sekarang) dan 'next' (berlaku mulai Senin depan). */
    public function index(Request $request, int $habit): JsonResponse
    {
        return response()->json($this->tasks->definitions($request->user(), $habit));
    }

    /** Ganti SELURUH daftar tugas milik pemanggil. Berlaku Senin depan bila habit sudah berjalan. */
    public function update(PutTasksRequest $request, int $habit): JsonResponse
    {
        return response()->json($this->tasks->replaceOwn($request->user(), $habit, $request->validated('tasks')));
    }

    /** Catatan bebas hari ini (null/kosong = hapus). */
    public function note(NoteRequest $request, int $habit): JsonResponse
    {
        return response()->json($this->progress->setNote($request->user(), $habit, $request->validated('note')));
    }

    /** Riwayat per hari, terbaru dulu, dengan kursor 'before'. */
    public function history(HistoryRequest $request, int $habit): JsonResponse
    {
        $limit = $request->validated('limit');

        return response()->json($this->progress->history(
            $request->user(),
            $habit,
            $request->validated('before'),
            $limit === null ? null : (int) $limit,
        ));
    }
}
