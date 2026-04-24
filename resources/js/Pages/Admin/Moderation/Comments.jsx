import { Head, router } from '@inertiajs/react';
import { useState, useCallback } from 'react';
import { resolveLayout } from '@/Layouts/resolveLayout';
import { Card } from '@/Components/UI/Card';
import { Btn } from '@/Components/UI/Btn';
import { DataTable } from '@/Components/UI/DataTable';
import { EmptyState } from '@/Components/UI/EmptyState';

const columns = [
    { key: 'body', label: 'Comment' },
    { key: 'author', label: 'Author' },
    { key: 'created_at', label: 'Created' },
    { key: 'actions', label: '' },
];

export default function Comments({ comments, filters = {} }) {
    const [search, setSearch] = useState(filters.search || '');
    const data = comments?.data || [];

    const navigate = useCallback((overrides = {}) => {
        router.get(route('stourify.admin.moderation.comments'), {
            search: search || undefined,
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    }, [search]);

    const deleteAndWarn = (comment) => {
        if (!confirm('Delete this comment and warn the author?')) return;
        router.delete(route('stourify.admin.moderation.warn', { type: 'comment', id: comment.id }));
    };

    return (
        <>
            <Head title="Comments — Moderation" />
            <div className="space-y-4">
                <h1 className="text-2xl font-bold text-text-primary">All Comments</h1>
                <Card noPadding>
                    <div className="p-4 border-b border-border-default/15">
                        <input
                            className="w-full px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary placeholder:text-text-muted focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                            placeholder="Search by comment body or author..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && navigate({ search: e.target.value, page: undefined })}
                        />
                    </div>
                    <DataTable
                        columns={columns}
                        rows={data}
                        renderRow={{
                            body: (row) => (
                                <span className="text-sm">
                                    {row.body?.substring(0, 80)}{row.body?.length > 80 ? '…' : ''}
                                </span>
                            ),
                            author: (row) => row.user?.name ?? '—',
                            created_at: (row) => new Date(row.created_at).toLocaleDateString(),
                            actions: (row) => (
                                <div className="flex justify-end">
                                    <Btn small danger onClick={() => deleteAndWarn(row)}>
                                        Delete + Warn
                                    </Btn>
                                </div>
                            ),
                        }}
                        emptyState={<EmptyState title="No comments found" />}
                    />
                </Card>
            </div>
        </>
    );
}

Comments.layout = resolveLayout;
