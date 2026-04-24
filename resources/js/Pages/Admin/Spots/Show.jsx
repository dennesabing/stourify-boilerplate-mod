import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import { resolveLayout } from '@/Layouts/resolveLayout';
import { Card } from '@/Components/UI/Card';
import { Btn } from '@/Components/UI/Btn';
import { Badge } from '@/Components/UI/Badge';

const STATUS_COLORS = {
    active: '#22c55e',
    pending: '#eab308',
};

export default function Show({ spot }) {
    const { data, setData, put, processing, errors } = useForm({
        name: spot.name || '',
        description: spot.description || '',
        latitude: spot.latitude || '',
        longitude: spot.longitude || '',
        address: spot.address || '',
        status: spot.status || 'pending',
    });

    const [mergeUuid, setMergeUuid] = useState('');
    const [mergeError, setMergeError] = useState('');

    const submit = (e) => {
        e.preventDefault();
        put(route('stourify.admin.spots.update', spot.id));
    };

    const handleMerge = () => {
        if (!mergeUuid.trim()) {
            setMergeError('Enter a target spot UUID.');
            return;
        }
        if (!confirm('This will move all posts to the target spot and delete this spot. Continue?')) return;
        router.post(route('stourify.admin.spots.merge', spot.id), { target_spot_uuid: mergeUuid });
    };

    const handleDelete = () => {
        if (!confirm(`Permanently delete "${spot.name}"?`)) return;
        router.delete(route('stourify.admin.spots.destroy', spot.id));
    };

    const inputClass = 'w-full px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary placeholder:text-text-muted focus:outline-none focus:ring-2 focus:ring-accent-500/30';

    return (
        <>
            <Head title={`Spot: ${spot.name}`} />
            <div className="max-w-2xl space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold text-text-primary">{spot.name}</h1>
                    <Badge color={STATUS_COLORS[spot.status] || '#6b7280'}>
                        {spot.status}
                    </Badge>
                </div>

                <Card>
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-text-secondary mb-1">Name *</label>
                            <input
                                className={inputClass}
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name && <p className="text-red-500 text-sm mt-1">{errors.name}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-text-secondary mb-1">Description</label>
                            <textarea
                                className={inputClass}
                                rows={3}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-text-secondary mb-1">Latitude *</label>
                                <input
                                    className={inputClass}
                                    type="number"
                                    step="any"
                                    value={data.latitude}
                                    onChange={(e) => setData('latitude', e.target.value)}
                                />
                                {errors.latitude && <p className="text-red-500 text-sm mt-1">{errors.latitude}</p>}
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-text-secondary mb-1">Longitude *</label>
                                <input
                                    className={inputClass}
                                    type="number"
                                    step="any"
                                    value={data.longitude}
                                    onChange={(e) => setData('longitude', e.target.value)}
                                />
                                {errors.longitude && <p className="text-red-500 text-sm mt-1">{errors.longitude}</p>}
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-text-secondary mb-1">Address</label>
                            <input
                                className={inputClass}
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-text-secondary mb-1">Status</label>
                            <select
                                className={inputClass}
                                value={data.status}
                                onChange={(e) => setData('status', e.target.value)}
                            >
                                <option value="pending">Pending</option>
                                <option value="active">Active</option>
                            </select>
                        </div>

                        <Btn type="submit" primary disabled={processing}>
                            Save Changes
                        </Btn>
                    </form>
                </Card>

                <Card>
                    <div className="space-y-3">
                        <h2 className="font-semibold text-text-primary">Merge into another spot</h2>
                        <p className="text-sm text-text-muted">
                            All posts from this spot will be moved to the target. This spot will be deleted.
                        </p>
                        <div className="flex gap-3">
                            <input
                                className="flex-1 px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary placeholder:text-text-muted focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                                placeholder="Target spot UUID"
                                value={mergeUuid}
                                onChange={(e) => { setMergeUuid(e.target.value); setMergeError(''); }}
                            />
                            <Btn onClick={handleMerge}>
                                Merge
                            </Btn>
                        </div>
                        {mergeError && <p className="text-red-500 text-sm">{mergeError}</p>}
                    </div>
                </Card>

                <Card>
                    <div className="space-y-3">
                        <h2 className="font-semibold text-red-500">Danger Zone</h2>
                        <Btn danger onClick={handleDelete}>
                            Delete Spot
                        </Btn>
                    </div>
                </Card>
            </div>
        </>
    );
}

Show.layout = resolveLayout;
