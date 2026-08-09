import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import { Head, router } from '@inertiajs/react';

export default function Jobs({ stats, pending = [], failed = [] }) {
    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.18em] text-ink-muted">
                        Super admin
                    </div>
                    <h2 className="font-display text-2xl font-bold tracking-tight text-ink">Jobs</h2>
                </div>
            }
        >
            <Head title="Admin · Jobs" />

            <div className="atlas-shell space-y-6">
                <section className="flex flex-wrap items-center justify-between gap-3">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="atlas-panel px-5 py-4">
                            <div className="text-xs font-semibold uppercase text-ink-muted">
                                Pending
                            </div>
                            <div className="mt-1 font-display text-3xl font-bold text-ink">
                                {stats.pending_jobs}
                            </div>
                        </div>
                        <div className="atlas-panel px-5 py-4">
                            <div className="text-xs font-semibold uppercase text-ink-muted">
                                Failed
                            </div>
                            <div className="mt-1 font-display text-3xl font-bold text-ink">
                                {stats.failed_jobs}
                            </div>
                        </div>
                    </div>
                    <SecondaryButton
                        type="button"
                        onClick={() => router.post(route('admin.jobs.flush'))}
                        disabled={!stats.failed_jobs}
                    >
                        Flush failed
                    </SecondaryButton>
                </section>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line/70 px-6 py-5">
                        <h3 className="font-display text-lg font-bold text-ink">Pending queue</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-mist/80 text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">ID</th>
                                    <th className="px-4 py-3 font-semibold">Job</th>
                                    <th className="px-4 py-3 font-semibold">Queue</th>
                                    <th className="px-4 py-3 font-semibold">Attempts</th>
                                    <th className="px-4 py-3 font-semibold">Available</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pending.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-4 py-8 text-center text-ink-muted"
                                        >
                                            Queue is empty
                                        </td>
                                    </tr>
                                ) : (
                                    pending.map((job) => (
                                        <tr key={job.id} className="border-t border-line/70">
                                            <td className="px-4 py-3 text-ink">{job.id}</td>
                                            <td className="px-4 py-3 font-semibold text-ink">
                                                {job.payload_summary}
                                            </td>
                                            <td className="px-4 py-3 text-ink-muted">{job.queue}</td>
                                            <td className="px-4 py-3 text-ink">{job.attempts}</td>
                                            <td className="px-4 py-3 text-ink-muted">
                                                {job.available_at}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line/70 px-6 py-5">
                        <h3 className="font-display text-lg font-bold text-ink">Failed jobs</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-mist/80 text-ink-muted">
                                <tr>
                                    <th className="px-4 py-3 font-semibold">Job</th>
                                    <th className="px-4 py-3 font-semibold">Failed at</th>
                                    <th className="px-4 py-3 font-semibold">Error</th>
                                    <th className="px-4 py-3 font-semibold">Retry</th>
                                </tr>
                            </thead>
                            <tbody>
                                {failed.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="px-4 py-8 text-center text-ink-muted"
                                        >
                                            No failed jobs
                                        </td>
                                    </tr>
                                ) : (
                                    failed.map((job) => (
                                        <tr key={job.id} className="border-t border-line/70 align-top">
                                            <td className="px-4 py-3 font-semibold text-ink">
                                                {job.payload_summary}
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-ink-muted">
                                                {job.failed_at}
                                            </td>
                                            <td className="px-4 py-3 max-w-md font-mono text-xs text-ink-muted">
                                                {job.exception}
                                            </td>
                                            <td className="px-4 py-3">
                                                <PrimaryButton
                                                    type="button"
                                                    onClick={() =>
                                                        router.post(
                                                            route('admin.jobs.retry', job.uuid),
                                                        )
                                                    }
                                                >
                                                    Retry
                                                </PrimaryButton>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
