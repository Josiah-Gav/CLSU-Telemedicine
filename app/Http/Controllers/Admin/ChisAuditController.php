<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChisAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin view of the CHIS audit log: who sent or viewed whose CHIS data, and
 * when. Read-only, and never shows clinical data (the log holds none).
 */
class ChisAuditController extends Controller
{
    private function authorizeAdmin(): void
    {
        if (Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }
    }

    public function index(Request $request): View
    {
        $this->authorizeAdmin();

        // Strings only, so ?action[]=x cannot reach a query or a TypeError.
        $param = fn (string $key) => is_string($value = $request->query($key)) ? trim($value) : '';

        $filters = [
            'action' => array_key_exists($param('action'), ChisAuditLog::ACTION_LABELS) ? $param('action') : null,
            'outcome' => in_array($param('outcome'), ChisAuditLog::OUTCOMES, true) ? $param('outcome') : null,
            'clsu_id' => $param('clsu_id') ?: null,
            'session' => ctype_digit($param('session')) ? (int) $param('session') : null,
        ];

        $logs = ChisAuditLog::query()
            ->when($filters['action'], fn ($q, $v) => $q->where('action', $v))
            ->when($filters['outcome'], fn ($q, $v) => $q->where('outcome', $v))
            ->when($filters['clsu_id'], fn ($q, $v) => $q->where('clsu_id', $v))
            ->when($filters['session'], fn ($q, $v) => $q->where('consultation_session_id', $v))
            ->with(['patient:user_id,first_name,last_name', 'actor:user_id,first_name,last_name'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.chis-audit.index', compact('logs', 'filters'));
    }
}
