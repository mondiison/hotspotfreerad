<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SalesReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesReportController extends Controller
{
    public function index(Request $request, SalesReportService $reports): View
    {
        return view('admin.reports.sales', [
            'filters' => $reports->filters($request->query()),
        ]);
    }

    public function export(Request $request, SalesReportService $reports): StreamedResponse
    {
        $report = $reports->build($request->user(), $request->query());
        $filename = 'sales-report-'.$report['filters']['from'].'-to-'.$report['filters']['to'].'.csv';

        return response()->streamDownload(function () use ($report, $reports): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Sales Report']);
            fputcsv($handle, ['From', $report['filters']['from']]);
            fputcsv($handle, ['To', $report['filters']['to']]);
            fputcsv($handle, ['Group', $report['filters']['group']]);
            fputcsv($handle, []);
            fputcsv($handle, ['Summary']);
            fputcsv($handle, ['Sales Count', $report['summary']['sales_count']]);
            fputcsv($handle, ['Gross Sales', number_format($report['summary']['revenue'], 2, '.', '')]);
            fputcsv($handle, ['Platform Commission', number_format($report['summary']['platform_fee'], 2, '.', '')]);
            fputcsv($handle, ['Tenant Net', number_format($report['summary']['tenant_net'], 2, '.', '')]);
            fputcsv($handle, ['Expenses', number_format($report['summary']['expenses'], 2, '.', '')]);
            fputcsv($handle, ['Estimated Profit', number_format($report['summary']['estimated_profit'], 2, '.', '')]);
            fputcsv($handle, ['Profit Margin', $reports->formatMargin($report['summary']['profit_margin'])]);
            fputcsv($handle, []);

            fputcsv($handle, ['Sales by Period']);
            fputcsv($handle, ['Period', 'Sales', 'Average Sale', 'Gross Sales', 'Platform Commission', 'Tenant Net', 'Expenses', 'Estimated Profit', 'Profit Margin']);
            foreach ($report['rows'] as $row) {
                fputcsv($handle, [
                    $row['period'],
                    $row['sales_count'],
                    number_format($row['average_sale'], 2, '.', ''),
                    number_format($row['revenue'], 2, '.', ''),
                    number_format($row['platform_fee'], 2, '.', ''),
                    number_format($row['tenant_net'], 2, '.', ''),
                    number_format($row['expenses'], 2, '.', ''),
                    number_format($row['estimated_profit'], 2, '.', ''),
                    $reports->formatMargin($row['profit_margin']),
                ]);
            }
            fputcsv($handle, []);

            fputcsv($handle, ['Sales by Shop']);
            fputcsv($handle, ['Shop', 'Sales', 'Gross Sales', 'Share', 'Platform Commission', 'Tenant Net']);
            foreach ($report['shopRows'] as $row) {
                fputcsv($handle, [
                    $row['shop'],
                    $row['sales_count'],
                    number_format($row['revenue'], 2, '.', ''),
                    $reports->formatMargin($row['share']),
                    number_format($row['platform_fee'], 2, '.', ''),
                    number_format($row['tenant_net'], 2, '.', ''),
                ]);
            }
            fputcsv($handle, []);

            fputcsv($handle, ['Sales by Package']);
            fputcsv($handle, ['Package', 'Shop', 'Sales', 'Average Sale', 'Gross Sales', 'Share', 'Platform Commission', 'Tenant Net']);
            foreach ($report['packageRows'] as $row) {
                fputcsv($handle, [
                    $row['package'],
                    $row['shop'],
                    $row['sales_count'],
                    number_format($row['average_sale'], 2, '.', ''),
                    number_format($row['revenue'], 2, '.', ''),
                    $reports->formatMargin($row['share']),
                    number_format($row['platform_fee'], 2, '.', ''),
                    number_format($row['tenant_net'], 2, '.', ''),
                ]);
            }
            fputcsv($handle, []);

            fputcsv($handle, ['Expenses by Category']);
            fputcsv($handle, ['Category', 'Count', 'Amount', 'Budget', 'Variance', 'Usage']);
            foreach ($report['expenseRows'] as $row) {
                fputcsv($handle, [
                    $row['category'],
                    $row['count'],
                    number_format($row['amount'], 2, '.', ''),
                    is_null($row['budget']) ? '' : number_format($row['budget'], 2, '.', ''),
                    is_null($row['variance']) ? '' : number_format($row['variance'], 2, '.', ''),
                    $reports->formatBudgetUsage($row['usage']),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
