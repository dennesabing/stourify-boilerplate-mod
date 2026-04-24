import { useEffect, useState } from 'react';
import axios from 'axios';

function UserList({ users, label }) {
    return (
        <div className="flex-1">
            <h3 className="font-semibold text-text-primary mb-3">
                {label} ({users.length})
            </h3>
            {users.length === 0 ? (
                <p className="text-sm text-text-muted">None</p>
            ) : (
                <ul className="space-y-2">
                    {users.map((u) => (
                        <li key={u.uuid} className="flex items-center gap-3">
                            <div className="w-8 h-8 rounded-full bg-accent-500 flex items-center justify-center text-white text-xs font-bold shrink-0">
                                {u.name?.charAt(0)?.toUpperCase() ?? '?'}
                            </div>
                            <div>
                                <a
                                    href={route('admin.users.show', u.id)}
                                    className="text-sm font-medium text-accent-500 hover:underline"
                                >
                                    {u.name}
                                </a>
                                <p className="text-xs text-text-muted">{u.email}</p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function FollowGraph({ user }) {
    const [data, setData] = useState({ followers: [], following: [] });
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!user?.id) return;
        axios.get(route('stourify.admin.users.follow-graph', user.id))
            .then((res) => setData(res.data))
            .catch(() => setError('Failed to load follow graph.'))
            .finally(() => setLoading(false));
    }, [user?.id]);

    if (loading) {
        return <div className="p-4 text-text-muted text-sm">Loading follow graph…</div>;
    }

    if (error) {
        return <div className="p-4 text-sm text-red-500">{error}</div>;
    }

    return (
        <div className="p-4 flex gap-8">
            <UserList users={data.followers} label="Followers" />
            <UserList users={data.following} label="Following" />
        </div>
    );
}
