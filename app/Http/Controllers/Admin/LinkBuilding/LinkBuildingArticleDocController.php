<?php

namespace App\Http\Controllers\Admin\LinkBuilding;

use App\Http\Controllers\Controller;
use App\Services\GoogleDocTitleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Article doc preview of the admin "Link Building Orders" table: hovering or
 * clicking the Article cell shows the title of the linked Google Doc, like
 * Google Sheets does in the Article column of the BASE Google Sheet.
 */
class LinkBuildingArticleDocController extends Controller
{
    public function __construct(
        private readonly GoogleDocTitleService $doc_titles,
    ) {}

    /**
     * GET /api/admin/link-building-orders/article-doc-title?url=...
     *
     * Response:
     *   data.url          : string       the link that was looked up
     *   data.document_id  : string|null  the Google file id, null when the link is not a Google file
     *   data.kind         : string|null  document, spreadsheets, presentation, forms or file
     *   data.title        : string|null  the file's title, null when it is not known
     *   data.status       : string       ok, private (not shared publicly or deleted),
     *                                    unavailable (Google did not answer) or unsupported (not a Google file link)
     */
    public function title(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $url  = trim($validated['url']);
        $link = $this->doc_titles->parseLink($url);

        if ($link === null) {
            return response()->json(['data' => [
                'url'         => $url,
                'document_id' => null,
                'kind'        => null,
                'title'       => null,
                'status'      => 'unsupported',
            ]]);
        }

        $result = $this->doc_titles->lookup($link);

        return response()->json(['data' => [
            'url'         => $url,
            'document_id' => $link['id'],
            'kind'        => $link['kind'],
            'title'       => $result['title'],
            'status'      => $result['status'],
        ]]);
    }
}
