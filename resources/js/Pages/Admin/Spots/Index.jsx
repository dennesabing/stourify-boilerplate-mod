import { Head, router } from '@inertiajs/react';
import { useState, useCallback } from 'react';
import { resolveLayout } from '@/Layouts/resolveLayout';
import { Card } from '@/Components/UI/Card';
import { Btn } from '@/Components/UI/Btn';
import { Badge } from '@/Components/UI/Badge';
import { DataTable } from '@/Components/UI/DataTable';
import { EmptyState } from '@/Components/UI/EmptyState';
import { MapPin, Plus } from 'lucide-react';

const STATUS_COLORS = {
    active: '#22c55e',
    pending: '#eab308',
};

const columns = [
    { key: 'name', label: 'Name' },
    { key: 'status', label: 'Status' },
    { key: 'created_at', label: 'Created' },
    { key: 'actions', label: '' },
];

export default function Index({ spots, filters = {} }) {
    const [search, setSearch] = useState(filters.search || '');
    const [status, setStatus] = useState(filters.status || 'all');

    const navigate = useCallback((overrides = {}) => {
        router.get(route('stourify.admin.spots.index'), {
            search: search || undefined,
            status: status !== 'all' ? status : undefined,
            ...overrides,
        }, { preserveState: true, preserveScroll: true });
    }, [search, status]);

    const handleDelete = (spot) => {
        if (!confirm(`Delete "${spot.name}"?`)) return;
        router.delete(route('stourify.admin.spots.destroy', spot.id));
    };

    const handleVerify = (spot) => {
        router.put(route('stourify.admin.spots.update', spot.id), {
            name: spot.name,
            latitude: spot.latitude,
            longitude: spot.longitude,
            status: 'active',
        });
    };

    const data = spots?.data || [];

    return (
        <>
            <Head title="Spots" />
            <div className="space-y-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold text-text-primary flex items-center gap-2">
                        <MapPin className="w-6 h-6" /> Spots
                    </h1>
                    <Btn href={route('stourify.admin.spots.create')} primary small>
                        <Plus className="w-4 h-4" /> Add Spot
                    </Btn>
                </div>

                <Card noPadding>
                    <div className="flex gap-3 p-4 border-b border-border-default/15">
                        <input
                            className="flex-1 px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary placeholder:text-text-muted focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                            placeholder="Search spots..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && navigate({ search: e.target.value, page: undefined })}
                        />
                        <select
                            className="w-40 px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                            value={status}
                            onChange={(e) => { setStatus(e.target.value); navigate({ status: e.target.value !== 'all' ? e.target.value : undefined, page: undefined }); }}
                        >
                            <option value="all">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="active">Active</option>
                        </select>
                    </div>

                    <DataTable
                        columns={columns}
                        rows={data}
                        renderRow={{
                            name: (spot) => (
                                <a
                                    href={route('stourify.admin.spots.show', spot.id)}
                                    className="font-medium text-accent-500 hover:underline"
                                >
                                    {spot.name}
                                </a>
                            ),
                            status: (spot) => (
                                <Badge color={STATUS_COLORS[spot.status] || '#6b7280'}>
                                    {spot.status}
                                </Badge>
                            ),
                            created_at: (spot) => new Date(spot.created_at).toLocaleDateString(),
                            actions: (spot) => (
                                <div className="flex gap-2 justify-end">
                                    {spot.status === 'pending' && (
                                        <Btn small onClick={() => handleVerify(spot)}>
                                            Verify
                                        </Btn>
                                    )}
                                    <Btn small href={route('stourify.admin.spots.show', spot.id)}>
                                        Edit
                                    </Btn>
                                    <Btn small danger onClick={() => handleDelete(spot)}>
                                        Delete
                                    </Btn>
                                </div>
                            ),
                        }}
                        emptyState={<EmptyState icon={MapPin} title="No spots found" />}
                    />
                </Card>
            </div>
        </>
    );
}

Index.layout = resolveLayout;
