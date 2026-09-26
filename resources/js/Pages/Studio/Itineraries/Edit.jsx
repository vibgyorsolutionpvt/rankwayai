import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { Head, Link, router, useForm } from '@inertiajs/react';

const money = (n, currency = 'INR') => {
    const v = Number(n || 0);
    if (currency === 'INR') return `₹${v.toLocaleString('en-IN')}`;
    return `${currency} ${v.toLocaleString()}`;
};

function emptyDay(dayNum) {
    return {
        day: dayNum,
        title: `Day ${dayNum}`,
        location: '',
        summary: '',
        activities: [{ title: '', time: 'Morning', notes: '' }],
        hotel: { name: '', category: '' },
        meals: ['Breakfast', 'Dinner'],
        transport: '',
        notes: '',
    };
}

export default function Edit({ workspace, itinerary, can_view_profit = false }) {
    const form = useForm({
        title: itinerary.title || '',
        status: itinerary.status || 'draft',
        destination: itinerary.destination || '',
        starting_city: itinerary.starting_city || '',
        duration_days: itinerary.duration_days || 1,
        travel_start: itinerary.travel_start || '',
        travel_end: itinerary.travel_end || '',
        adults: itinerary.adults || 2,
        children: itinerary.children || 0,
        budget_band: itinerary.budget_band || 'standard',
        hotel_category: itinerary.hotel_category || '',
        transport: itinerary.transport || '',
        interests_text: itinerary.interests_text || '',
        meal_preference: itinerary.meal_preference || '',
        special_requirements: itinerary.special_requirements || '',
        overview: itinerary.overview || '',
        inclusions_text: itinerary.inclusions_text || '',
        exclusions_text: itinerary.exclusions_text || '',
        currency: itinerary.currency || 'INR',
        is_public: !!itinerary.is_public,
        pricing: {
            hotel_cost: itinerary.pricing?.hotel_cost ?? 0,
            transport_cost: itinerary.pricing?.transport_cost ?? 0,
            activity_cost: itinerary.pricing?.activity_cost ?? 0,
            meal_cost: itinerary.pricing?.meal_cost ?? 0,
            guide_cost: itinerary.pricing?.guide_cost ?? 0,
            tax: itinerary.pricing?.tax ?? 0,
            discount: itinerary.pricing?.discount ?? 0,
            markup: itinerary.pricing?.markup ?? 0,
        },
        days: (itinerary.days || []).map((d) => ({
            ...d,
            meals_text: Array.isArray(d.meals) ? d.meals.join(', ') : '',
            activities: d.activities?.length ? d.activities : [{ title: '', time: 'Morning', notes: '' }],
            hotel: d.hotel || { name: '', category: '' },
        })),
    });

    const setPricing = (key, value) => {
        form.setData('pricing', { ...form.data.pricing, [key]: value });
    };

    const setDay = (index, patch) => {
        form.setData(
            'days',
            form.data.days.map((day, i) => (i === index ? { ...day, ...patch } : day)),
        );
    };

    const setActivity = (dayIndex, actIndex, patch) => {
        const days = form.data.days.map((day, i) => {
            if (i !== dayIndex) return day;
            const activities = (day.activities || []).map((a, j) =>
                j === actIndex ? { ...a, ...patch } : a,
            );
            return { ...day, activities };
        });
        form.setData('days', days);
    };

    const addDay = () => {
        const nextNum = (form.data.days?.length || 0) + 1;
        form.setData('days', [...form.data.days, emptyDay(nextNum)]);
        form.setData('duration_days', nextNum);
    };

    const removeDay = (index) => {
        const next = form.data.days
            .filter((_, i) => i !== index)
            .map((day, i) => ({ ...day, day: i + 1 }));
        form.setData('days', next);
        form.setData('duration_days', Math.max(1, next.length));
    };

    const moveDay = (index, dir) => {
        const target = index + dir;
        if (target < 0 || target >= form.data.days.length) return;
        const next = [...form.data.days];
        const tmp = next[index];
        next[index] = next[target];
        next[target] = tmp;
        form.setData(
            'days',
            next.map((day, i) => ({ ...day, day: i + 1 })),
        );
    };

    const liveTotals = (() => {
        const p = form.data.pricing || {};
        const base =
            Number(p.hotel_cost || 0) +
            Number(p.transport_cost || 0) +
            Number(p.activity_cost || 0) +
            Number(p.meal_cost || 0) +
            Number(p.guide_cost || 0) +
            Number(p.tax || 0) -
            Number(p.discount || 0);
        const selling = base + Number(p.markup || 0);
        const profit = selling - base;
        const margin = selling > 0 ? (profit / selling) * 100 : 0;
        return {
            base_cost: Math.max(0, base),
            selling_price: Math.max(0, selling),
            profit,
            margin,
        };
    })();

    return (
        <AuthenticatedLayout
            header={
                <div>
                    <div className="text-xs font-semibold uppercase tracking-[0.16em] text-ink-muted">
                        <Link href={route('studio.itineraries.index')} className="hover:text-ink">
                            Itineraries
                        </Link>{' '}
                        / Edit
                    </div>
                    <h2 className="font-display text-2xl font-bold text-ink">{itinerary.title}</h2>
                </div>
            }
        >
            <Head title={itinerary.title} />
            <form
                className="atlas-shell space-y-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form
                        .transform((data) => ({
                            ...data,
                            days: (data.days || []).map((day) => ({
                                ...day,
                                meals: String(day.meals_text || '')
                                    .split(',')
                                    .map((m) => m.trim())
                                    .filter(Boolean),
                            })),
                        }))
                        .post(route('studio.itineraries.update', itinerary.id));
                }}
            >
                <section className="atlas-panel grid gap-3 p-4 md:grid-cols-2">
                    <div className="md:col-span-2 font-display text-sm font-bold text-ink">Trip details</div>
                    <div className="md:col-span-2">
                        <InputLabel value="Title *" />
                        <TextInput
                            className="mt-1 w-full"
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            required
                        />
                    </div>
                    <div>
                        <InputLabel value="Status" />
                        <div className="mt-1">
                            <SelectMenu
                                value={form.data.status}
                                onChange={(v) => form.setData('status', v)}
                                options={[
                                    { value: 'draft', label: 'Draft' },
                                    { value: 'ready', label: 'Ready' },
                                    { value: 'archived', label: 'Archived' },
                                ]}
                            />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Destination" />
                        <TextInput
                            className="mt-1 w-full"
                            value={form.data.destination}
                            onChange={(e) => form.setData('destination', e.target.value)}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <InputLabel value="Overview" />
                        <textarea
                            className="mt-1 w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                            rows={3}
                            value={form.data.overview}
                            onChange={(e) => form.setData('overview', e.target.value)}
                        />
                    </div>
                </section>

                <section className="space-y-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="font-display text-base font-bold text-ink">Day-wise plan</h3>
                        <button
                            type="button"
                            onClick={addDay}
                            className="rounded-md border border-line px-3 py-1.5 text-xs font-semibold hover:bg-mist"
                        >
                            Add day
                        </button>
                    </div>

                    {form.data.days.map((day, dayIndex) => (
                        <div key={`day-${day.day}-${dayIndex}`} className="atlas-panel space-y-3 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="font-semibold text-ink">Day {day.day}</div>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        className="text-xs font-semibold text-ink-muted"
                                        onClick={() => moveDay(dayIndex, -1)}
                                    >
                                        Up
                                    </button>
                                    <button
                                        type="button"
                                        className="text-xs font-semibold text-ink-muted"
                                        onClick={() => moveDay(dayIndex, 1)}
                                    >
                                        Down
                                    </button>
                                    <button
                                        type="button"
                                        className="text-xs font-semibold text-signal-strong"
                                        onClick={() => {
                                            const instruction = window.prompt(
                                                'Optional instruction (e.g. Make more adventurous)',
                                                '',
                                            );
                                            router.post(
                                                route('studio.itineraries.regenerate-day', itinerary.id),
                                                {
                                                    day: day.day,
                                                    instruction: instruction || '',
                                                },
                                                { preserveScroll: true },
                                            );
                                        }}
                                    >
                                        Regenerate
                                    </button>
                                    <button
                                        type="button"
                                        className="text-xs font-semibold text-rose-700"
                                        onClick={() => removeDay(dayIndex)}
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                            <div className="grid gap-2 md:grid-cols-2">
                                <TextInput
                                    className="w-full"
                                    placeholder="Day title"
                                    value={day.title || ''}
                                    onChange={(e) => setDay(dayIndex, { title: e.target.value })}
                                />
                                <TextInput
                                    className="w-full"
                                    placeholder="Location"
                                    value={day.location || ''}
                                    onChange={(e) => setDay(dayIndex, { location: e.target.value })}
                                />
                                <textarea
                                    className="md:col-span-2 w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                                    rows={2}
                                    placeholder="Summary"
                                    value={day.summary || ''}
                                    onChange={(e) => setDay(dayIndex, { summary: e.target.value })}
                                />
                                <TextInput
                                    className="w-full"
                                    placeholder="Hotel name"
                                    value={day.hotel?.name || ''}
                                    onChange={(e) =>
                                        setDay(dayIndex, {
                                            hotel: { ...(day.hotel || {}), name: e.target.value },
                                        })
                                    }
                                />
                                <TextInput
                                    className="w-full"
                                    placeholder="Transport"
                                    value={day.transport || ''}
                                    onChange={(e) => setDay(dayIndex, { transport: e.target.value })}
                                />
                                <TextInput
                                    className="md:col-span-2 w-full"
                                    placeholder="Meals (comma separated)"
                                    value={day.meals_text || ''}
                                    onChange={(e) => setDay(dayIndex, { meals_text: e.target.value })}
                                />
                            </div>
                            <div className="space-y-2">
                                <div className="text-xs font-bold uppercase tracking-wide text-ink-muted">
                                    Activities
                                </div>
                                {(day.activities || []).map((activity, actIndex) => (
                                    <div key={actIndex} className="grid gap-2 md:grid-cols-3">
                                        <TextInput
                                            className="w-full"
                                            placeholder="Activity"
                                            value={activity.title || ''}
                                            onChange={(e) =>
                                                setActivity(dayIndex, actIndex, {
                                                    title: e.target.value,
                                                })
                                            }
                                        />
                                        <TextInput
                                            className="w-full"
                                            placeholder="Time"
                                            value={activity.time || ''}
                                            onChange={(e) =>
                                                setActivity(dayIndex, actIndex, {
                                                    time: e.target.value,
                                                })
                                            }
                                        />
                                        <TextInput
                                            className="w-full"
                                            placeholder="Notes"
                                            value={activity.notes || ''}
                                            onChange={(e) =>
                                                setActivity(dayIndex, actIndex, {
                                                    notes: e.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    className="text-xs font-semibold text-signal-strong"
                                    onClick={() =>
                                        setDay(dayIndex, {
                                            activities: [
                                                ...(day.activities || []),
                                                { title: '', time: 'Afternoon', notes: '' },
                                            ],
                                        })
                                    }
                                >
                                    + Add activity
                                </button>
                            </div>
                        </div>
                    ))}
                </section>

                <section className="atlas-panel grid gap-3 p-4 md:grid-cols-2">
                    <div className="md:col-span-2 font-display text-sm font-bold text-ink">
                        Inclusions / exclusions
                    </div>
                    <textarea
                        className="w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                        rows={4}
                        placeholder="Inclusions (one per line)"
                        value={form.data.inclusions_text}
                        onChange={(e) => form.setData('inclusions_text', e.target.value)}
                    />
                    <textarea
                        className="w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                        rows={4}
                        placeholder="Exclusions (one per line)"
                        value={form.data.exclusions_text}
                        onChange={(e) => form.setData('exclusions_text', e.target.value)}
                    />
                </section>

                <section className="atlas-panel grid gap-3 p-4 md:grid-cols-4">
                    <div className="md:col-span-4 font-display text-sm font-bold text-ink">Pricing</div>
                    {[
                        ['hotel_cost', 'Hotel'],
                        ['transport_cost', 'Transport'],
                        ['activity_cost', 'Activities'],
                        ['meal_cost', 'Meals'],
                        ['guide_cost', 'Guide'],
                        ['tax', 'Tax'],
                        ['discount', 'Discount'],
                        ['markup', 'Markup'],
                    ].map(([key, label]) => (
                        <div key={key}>
                            <InputLabel value={label} />
                            <TextInput
                                type="number"
                                min="0"
                                step="0.01"
                                className="mt-1 w-full"
                                value={form.data.pricing[key]}
                                onChange={(e) => setPricing(key, e.target.value)}
                            />
                        </div>
                    ))}
                    <div className="md:col-span-4 grid gap-2 rounded-lg border border-line bg-mist/40 p-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <div className="text-[10px] font-bold uppercase text-ink-muted">Base cost</div>
                            <div className="font-semibold text-ink">
                                {money(liveTotals.base_cost, form.data.currency)}
                            </div>
                        </div>
                        <div>
                            <div className="text-[10px] font-bold uppercase text-ink-muted">
                                Selling price
                            </div>
                            <div className="font-semibold text-ink">
                                {money(liveTotals.selling_price, form.data.currency)}
                            </div>
                        </div>
                        {can_view_profit ? (
                            <>
                                <div>
                                    <div className="text-[10px] font-bold uppercase text-ink-muted">
                                        Profit
                                    </div>
                                    <div className="font-semibold text-emerald-800">
                                        {money(liveTotals.profit, form.data.currency)}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-[10px] font-bold uppercase text-ink-muted">
                                        Margin
                                    </div>
                                    <div className="font-semibold text-ink">
                                        {Number(liveTotals.margin || 0).toFixed(1)}%
                                    </div>
                                </div>
                            </>
                        ) : (
                            <div className="sm:col-span-2 text-xs text-ink-muted">
                                Profit/margin visible to owners & admins only.
                            </div>
                        )}
                    </div>
                </section>

                <div className="flex flex-wrap items-center gap-2">
                    <PrimaryButton processing={form.processing}>Save itinerary</PrimaryButton>
                    <button
                        type="button"
                        className="rounded-md border border-violet-200 bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-900 hover:bg-violet-100"
                        onClick={() =>
                            router.post(route('studio.itineraries.quotation', itinerary.id))
                        }
                    >
                        Generate quotation
                    </button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
