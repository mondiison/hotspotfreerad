<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Services\ClientSalesReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request, ClientSalesReportService $reports): View
    {
        $report = $reports->build($request->user(), $request->only(['preset']));

        return view('client.reports.index', $report);
    }
}
