import { Head, router } from '@inertiajs/react';
import { useState, useCallback } from 'react';
import { resolveLayout } from '@/Layouts/resolveLayout';
import { Card } from '@/Components/UI/Card';
import { Btn } from '@/Components/UI/Btn';
import { Badge } from '@/Components/UI/Badge';
import { DataTable } from '@/Components/UI/DataTable';
import { EmptyState } from '@/Components/UI/EmptyState';

const columns = [
    { key: 'caption', label: 'Caption' },
    { key: 'author', label: 'Author' },
    { key: 'visibility', label: 'Visibility' },
    { key: 'created_at', label: 'Created' },
    { key: 'actions', label: '' },
];

const visibilityColors = { public: '#22c55e', followers: '#3b82f6', private: '#6b7280' };

export default function Posts({ posts, filters = {} }) {
    const [search, setSearch] = useState(filters.search || '');
    const data = posts?.data || [];

    const navigate = useCallback((overrides = {}) => {
        router.get(route('stourify.admin.moderation.posts'), {
            search: search || undefined,
            visibility: filters.visibility || undefined,
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    }, [search, filters.visibility]);

    const deleteAndWarn = (post) => {
        if (!confirm('Delete this post and warn the author?')) return;
        router.delete(route('stourify.admin.moderation.warn', { type: 'post', id: post.id }));
    };

    return (
        <>
            <Head title="Posts — Moderation" />
            <div className="space-y-4">
                <h1 className="text-2xl font-bold text-text-primary">All Posts</h1>
                <Card noPadding>
                    <div className="flex gap-3 p-4 border-b border-border-default/15">
                        <input
                            className="flex-1 px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary placeholder:text-text-muted focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                            placeholder="Search by caption or author..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && navigate({ search: e.target.value, page: undefined })}
                        />
                        <select
                            className="w-40 px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                            value={filters.visibility || ''}
                            onChange={(e) => navigate({ visibility: e.target.value || undefined, page: undefined })}
                        >
                            <option value="">All visibility</option>
                            <option value="public">Public</option>
                            <option value="followers">Followers</option>
                            <option value="private">Private</option>
                        </select>
                    </div>
                    <DataTable
                        columns={columns}
                        rows={data}
                        renderRow={{
                            caption: (row) => (
                                <span className="text-sm">
                                    {row.caption
                                        ? row.caption.substring(0, 60) + (row.caption.length > 60 ? '…' : '')
                                        : <em className="text-text-muted">No caption</em>}
                                </span>
                            ),
                            author: (row) => row.user?.name ?? '—',
                            visibility: (row) => (
                                <Badge color={visibilityColors[row.visibility] ?? '#6b7280'}>
                                    {row.visibility}
                                </Badge>
                            ),
                            created_at: (row) => new Date(row.created_at).toLocaleDateString(),
                            actions: (row) => (
                                <div className="flex justify-end">
                                    <Btn small danger onClick={() => deleteAndWarn(row)}>
                                        Delete + Warn
                                    </Btn>
                                </div>
                            ),
                        }}
                        emptyState={<EmptyState title="No posts found" />}
                    />
                </Card>
            </div>
        </>
    );
}

Posts.layout = resolveLayout;
