<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CustomerContactDirectoryService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 2026-11-10, direct request: "i want a place where i can see the unique
 * phone numbers i want to use it and open whatsapp group for complaints
 * and educational purpose" -- see CustomerContactDirectoryService's own
 * docblock for the actual merge/dedup logic this just renders/exports.
 */
class CustomerContactController extends Controller
{
    public function index(): View
    {
        return view('admin.customer-contacts.index');
    }

    public function export(Request $request, CustomerContactDirectoryService $directory): StreamedResponse
    {
        $shopId = $request->query('shop') !== null && $request->query('shop') !== ''
            ? (int) $request->query('shop')
            : null;
        $search = (string) $request->query('search', '');
        $filename = 'customer-contacts-'.now()->format('Y-m-d-His').'.csv';

        $contacts = $directory->uniqueContacts($request->user(), $shopId);

        if ($search !== '') {
            $needle = strtolower($search);
            $contacts = $contacts->filter(fn (array $contact): bool => str_contains($contact['phone'], $search)
                || str_contains(strtolower((string) $contact['contact_name']), $needle)
                || collect($contact['shops'])->contains(fn (string $shop): bool => str_contains(strtolower($shop), $needle)));
        }

        return response()->streamDownload(function () use ($contacts): void {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Customer Contacts']);
            fputcsv($handle, []);
            fputcsv($handle, ['Phone', 'Name', 'Seen As', 'Shop(s)']);

            foreach ($contacts as $contact) {
                fputcsv($handle, [
                    $contact['phone'],
                    $contact['contact_name'],
                    implode('/', $contact['sources']),
                    implode(', ', $contact['shops']),
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
