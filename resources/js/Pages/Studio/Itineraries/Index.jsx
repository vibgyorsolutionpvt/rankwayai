import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StudioSubnav from '@/Components/Studio/StudioSubnav';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { Head, Link, router, useForm } from '@inertiajs/react';

const money = (n, currency = 'INR') => {
    const v = Number(n || 0);
    if (currency === 'INR') return `₹${v.toLocaleString('en-IN')}`;
    return `${currency} ${v.toLocaleString()}`;
};

export default function Index({ workspace, itineraries = [], defaults = {} }) {
    const form = useForm({
        destination: defaults.destination || '',
        starting_city: defaults.starting_city || '',
        duration_days: defaults.duration_days || 5,
        adults: defaults.adults || 2,
        children: defaults.children || 0,
        budget_band: defaults.budget_band || 'standard',
        hotel_category: defaults.hotel_category || '3-star',
        transport: defaults.transport || 'Private cab',
        meal_preference: defaults.meal_preference || 'MAP',
        interests_text: '',
        special_requirements: '',
        travel_start: '',
        travel_end: '',
        currency: 'INR',
    });

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <h2 className="font-display text-2xl font-bold leading-tight text-ink">
                        Business Studio
                    </h2>
                </div>
            }
        >
            <Head title="Itineraries" />
            <div className="atlas-shell space-y-4">
                <StudioSubnav active="itineraries" />
                <form
                    className="atlas-panel grid gap-3 p-4 md:grid-cols-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(route('studio.itineraries.store'));
                    }}
                >
                    <div className="md:col-span-2">
                        <h3 className="font-display text-lg font-bold text-ink">Generate itinerary</h3>
                        <p className="text-sm text-ink-muted">
                            Uses AI when configured; otherwise a solid editable template.
                        </p>
                    </div>
                    <div>
                        <InputLabel value="Destination *" />
                        <TextInput
                            className="mt-1 w-full"
                            placeholder="Kashmir, Goa, Manali…"
                            value={form.data.destination}
                            onChange={(e) => form.setData('destination', e.target.value)}
                            required
                        />
                    </div>
                    <div>
                        <InputLabel value="Starting city" />
                        <TextInput
                            className="mt-1 w-full"
                            value={form.data.starting_city}
                            onChange={(e) => form.setData('starting_city', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Duration (days)" />
                        <TextInput
                            type="number"
                            min="1"
                            max="21"
                            className="mt-1 w-full"
                            value={form.data.duration_days}
                            onChange={(e) => form.setData('duration_days', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Budget band" />
                        <div className="mt-1">
                            <SelectMenu
                                value={form.data.budget_band}
                                onChange={(v) => form.setData('budget_band', v)}
                                options={[
                                    { value: 'economy', label: 'Economy' },
                                    { value: 'standard', label: 'Standard' },
                                    { value: 'premium', label: 'Premium' },
                                    { value: 'luxury', label: 'Luxury' },
                                ]}
                            />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Adults" />
                        <TextInput
                            type="number"
                            min="1"
                            className="mt-1 w-full"
                            value={form.data.adults}
                            onChange={(e) => form.setData('adults', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Children" />
                        <TextInput
                            type="number"
                            min="0"
                            className="mt-1 w-full"
                            value={form.data.children}
                            onChange={(e) => form.setData('children', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Hotel category" />
                        <TextInput
                            className="mt-1 w-full"
                            value={form.data.hotel_category}
                            onChange={(e) => form.setData('hotel_category', e.target.value)}
                        />
                    </div>
                    <div>
                        <InputLabel value="Transport" />
                        <TextInput
                            className="mt-1 w-full"
                            value={form.data.transport}
                            onChange={(e) => form.setData('transport', e.target.value)}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <InputLabel value="Interests" />
                        <textarea
                            className="mt-1 w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                            rows={2}
                            placeholder="Sightseeing, adventure, family…"
                            value={form.data.interests_text}
                            onChange={(e) => form.setData('interests_text', e.target.value)}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <PrimaryButton processing={form.processing}>Generate itinerary</PrimaryButton>
                    </div>
                </form>

                <section className="atlas-panel overflow-hidden">
                    <div className="border-b border-line px-4 py-3">
                        <h3 className="font-display text-base font-bold text-ink">Your itineraries</h3>
                    </div>
                    {itineraries.length === 0 ? (
                        <p className="px-4 py-8 text-center text-sm text-ink-muted">No itineraries yet.</p>
                    ) : (
                        <div className="divide-y divide-line">
                            {itineraries.map((item) => (
                                <div
                                    key={item.id}
                                    className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div>
                                        <div className="font-semibold text-ink">{item.title}</div>
                                        <div className="mt-1 text-xs text-ink-muted">
                                            {item.destination} · {item.duration_days}D · {item.status} ·{' '}
                                            {item.generation_source || 'manual'} · selling{' '}
                                            {money(item.totals?.selling_price, item.currency)}
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <Link
                                            href={route('studio.itineraries.edit', item.id)}
                                            className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                                        >
                                            Edit
                                        </Link>
                                        <button
                                            type="button"
                                            className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                                            onClick={() =>
                                                router.post(route('studio.itineraries.duplicate', item.id))
                                            }
                                        >
                                            Duplicate
                                        </button>
                                        <button
                                            type="button"
                                            className="rounded-md border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50"
                                            onClick={async () => {
                                                const ok = await confirmAsk({
                                                    title: 'Delete itinerary?',
                                                    message: `"${item.title}" will be removed.`,
                                                    confirmLabel: 'Delete',
                                                });
                                                if (ok) {
                                                    router.delete(route('studio.itineraries.destroy', item.id));
                                                }
                                            }}
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
