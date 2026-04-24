import { Head, useForm } from '@inertiajs/react';
import { resolveLayout } from '@/Layouts/resolveLayout';
import { Card } from '@/Components/UI/Card';
import { Btn } from '@/Components/UI/Btn';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        description: '',
        latitude: '',
        longitude: '',
        address: '',
        status: 'pending',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('stourify.admin.spots.store'));
    };

    return (
        <>
            <Head title="Create Spot" />
            <div className="max-w-2xl space-y-4">
                <h1 className="text-2xl font-bold text-text-primary">Create Spot</h1>
                <Card>
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-text-secondary mb-1">Name *</label>
                            <input
                                className="w-full px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary placeholder:text-text-muted focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name && <p className="text-red-500 text-sm mt-1">{errors.name}</p>}
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-text-secondary mb-1">Description</label>
                            <textarea
                                className="w-full px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary placeholder:text-text-muted focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                                rows={3}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-text-secondary mb-1">Latitude *</label>
                                <input
                                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary focus:outline-none focus:ring-2 focus:ring-accent-500/30"
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
                                    className="w-full px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary focus:outline-none focus:ring-2 focus:ring-accent-500/30"
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
                                className="w-full px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-text-secondary mb-1">Status</label>
                            <select
                                className="w-full px-3 py-2 text-sm rounded-lg border border-border-default bg-surface text-text-primary focus:outline-none focus:ring-2 focus:ring-accent-500/30"
                                value={data.status}
                                onChange={(e) => setData('status', e.target.value)}
                            >
                                <option value="pending">Pending</option>
                                <option value="active">Active</option>
                            </select>
                        </div>

                        <div className="flex gap-3 pt-2">
                            <Btn type="submit" primary disabled={processing}>
                                Create Spot
                            </Btn>
                            <Btn href={route('stourify.admin.spots.index')}>
                                Cancel
                            </Btn>
                        </div>
                    </form>
                </Card>
            </div>
        </>
    );
}

Create.layout = resolveLayout;
