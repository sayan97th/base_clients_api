<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Resource;
use App\Models\ResourceFile;
use Illuminate\Database\Seeder;

class ResourceSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::where('slug', 'base-search-marketing')->first();

        if (! $organization) {
            return;
        }

        $resources = [
            [
                'title'       => 'February KW Movement 3 Month Comparison',
                'description' => 'Monthly keyword ranking comparison for Q1 2025, including position changes, estimated traffic impact and competitor movements.',
                'category'    => 'spreadsheet',
                'files'       => [
                    [
                        'name'       => 'feb-kw-movement.xlsx',
                        'file_type'  => 'xlsx',
                        'size_bytes' => 204800,
                        'file_path'  => 'resources/feb-kw-movement.xlsx',
                    ],
                    [
                        'name'       => 'feb-kw-movement-summary.pdf',
                        'file_type'  => 'pdf',
                        'size_bytes' => 102400,
                        'file_path'  => 'resources/feb-kw-movement-summary.pdf',
                    ],
                ],
            ],
            [
                'title'       => 'January Review',
                'description' => null,
                'category'    => 'document',
                'files'       => [
                    [
                        'name'       => 'january-review.docx',
                        'file_type'  => 'docx',
                        'size_bytes' => 51200,
                        'file_path'  => 'resources/january-review.docx',
                    ],
                ],
            ],
            [
                'title'       => 'Q4 2024 Link Building Report',
                'description' => 'Summary of all link placements completed in Q4 2024 with DR scores and live link verification.',
                'category'    => 'pdf',
                'files'       => [
                    [
                        'name'       => 'q4-2024-link-building-report.pdf',
                        'file_type'  => 'pdf',
                        'size_bytes' => 358400,
                        'file_path'  => 'resources/q4-2024-link-building-report.pdf',
                    ],
                ],
            ],
            [
                'title'       => 'Brand Assets Pack',
                'description' => 'Official logo files and brand guidelines for your organization.',
                'category'    => 'image',
                'files'       => [
                    [
                        'name'       => 'logo-primary.png',
                        'file_type'  => 'png',
                        'size_bytes' => 76800,
                        'file_path'  => 'resources/logo-primary.png',
                    ],
                    [
                        'name'       => 'logo-dark.png',
                        'file_type'  => 'png',
                        'size_bytes' => 71680,
                        'file_path'  => 'resources/logo-dark.png',
                    ],
                ],
            ],
            [
                'title'       => 'SEO Strategy Presentation 2025',
                'description' => 'Annual SEO strategy deck covering target keywords, link velocity goals and content roadmap.',
                'category'    => 'presentation',
                'files'       => [
                    [
                        'name'       => 'seo-strategy-2025.pptx',
                        'file_type'  => 'pptx',
                        'size_bytes' => 921600,
                        'file_path'  => 'resources/seo-strategy-2025.pptx',
                    ],
                ],
            ],
        ];

        foreach ($resources as $resource_data) {
            $files = $resource_data['files'];
            unset($resource_data['files']);

            $resource = Resource::updateOrCreate(
                [
                    'title'           => $resource_data['title'],
                    'organization_id' => $organization->id,
                ],
                array_merge($resource_data, ['organization_id' => $organization->id])
            );

            foreach ($files as $file_data) {
                ResourceFile::updateOrCreate(
                    [
                        'resource_id' => $resource->id,
                        'file_path'   => $file_data['file_path'],
                    ],
                    array_merge($file_data, ['resource_id' => $resource->id])
                );
            }
        }
    }
}
