import { Head, router } from '@inertiajs/react';
import { resolveLayout } from '@/Layouts/resolveLayout';
import { Card } from '@/Components/UI/Card';
import { Btn } from '@/Components/UI/Btn';
import { Badge } from '@/Components/UI/Badge';
import { EmptyState } from '@/Components/UI/EmptyState';
import { Shield } from 'lucide-react';

export default function Queue({ reports, filters = {} }) {
    const data = reports?.data || [];

    const dismiss = (report) => {
        if (!confirm('Dismiss this report? Content will be kept.')) return;
        router.delete(route('stourify.admin.moderation.dismiss', report.id));
    };

    const deleteAndWarn = (reportableType, reportableId) => {
        const type = reportableType.includes('Post') ? 'post' : 'comment';
        if (!confirm(`Delete this ${type} and warn the author?`)) return;
        router.delete(route('stourify.admin.moderation.warn', { type, id: reportableId }));
    };

    const setTypeFilter = (type) => {
        router.get(route('stourify.admin.moderation.queue'), { type: type || undefined }, { preserveState: true });
    };

    return (
        <>
            <Head title="Moderation Queue" />
            <div className="space-y-4">
                <div className="flex items-center gap-2">
                    <Shield className="w-6 h-6 text-accent-500" />
                    <h1 className="text-2xl font-bold text-text-primary">Moderation Queue</h1>
                </div>

                <div className="flex gap-2">
                    {['all', 'post', 'comment'].map((t) => (
                        <button
                            key={t}
                            onClick={() => setTypeFilter(t === 'all' ? '' : t)}
                            className={`px-3 py-1 rounded-full text-sm font-medium transition-colors ${
                                (filters.type || 'all') === t
                                    ? 'bg-accent-500 text-white'
                                    : 'text-text-muted hover:text-text-primary'
                            }`}
                        >
                            {t.charAt(0).toUpperCase() + t.slice(1)}
                        </button>
                    ))}
                </div>

                {data.length === 0 ? (
                    <EmptyState
                        icon={Shield}
                        title="No pending reports"
                        subtitle="The queue is clear."
                    />
                ) : (
                    <div className="space-y-3">
                        {data.map((report) => (
                            <Card key={`${report.reportable_type}-${report.reportable_id}`}>
                                <div className="flex items-start justify-between gap-4">
                                    <div className="space-y-1 flex-1">
                                        <div className="flex items-center gap-2">
                                            <Badge color={report.reportable_type.includes('Post') ? '#3b82f6' : '#8b5cf6'}>
                                                {report.reportable_type.includes('Post') ? 'post' : 'comment'}
                                            </Badge>
                                            <Badge color="#ef4444">
                                                {report.report_count} report{report.report_count > 1 ? 's' : ''}
                                            </Badge>
                                        </div>
                                        <p className="text-sm text-text-muted">Reasons: {report.reasons}</p>
                                        <p className="text-xs text-text-muted">
                                            Latest: {new Date(report.latest_report_at).toLocaleString()}
                                        </p>
                                    </div>
                                    <div className="flex gap-2 shrink-0">
                                        <Btn small ghost onClick={() => dismiss(report)}>
                                            Dismiss
                                        </Btn>
                                        <Btn small danger onClick={() => deleteAndWarn(report.reportable_type, report.reportable_id)}>
                                            Delete + Warn
                                        </Btn>
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

Queue.layout = resolveLayout;
