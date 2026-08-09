import InputLabel from '@/Components/InputLabel';
import HelpGuide, { PROVIDER_HELP } from '@/Components/HelpGuide';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import Toggle from '@/Components/Toggle';
import { confirmAsk } from '@/Components/ConfirmProvider';
import { router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function ProvidersPanel({
    categories = {},
    integrations = [],
    initialCategory = 'social',
    initialConfigure = null,
}) {
    const categoryIds = Object.keys(categories);
    const startCategory = categoryIds.includes(initialCategory)
        ? initialCategory
        : categoryIds[0] || 'social';
    const [activeCategory, setActiveCategory] = useState(startCategory);
    const [editing, setEditing] = useState(initialConfigure || null);

    const grouped = useMemo(() => {
        const map = {};
        for (const item of integrations) {
            map[item.category] = map[item.category] || [];
            map[item.category].push(item);
        }
        return map;
    }, [integrations]);

    const list = grouped[activeCategory] || [];

    return (
        <div className="space-y-4">
            <section className="flex flex-wrap gap-1 rounded-md border border-line bg-white p-1">
                {categoryIds.map((id) => {
                    const active = activeCategory === id;
                    const count = (grouped[id] || []).filter((i) => i.connected).length;
                    return (
                        <button
                            key={id}
                            type="button"
                            onClick={() => {
                                setActiveCategory(id);
                                setEditing(null);
                            }}
                            className={
                                'inline-flex items-center gap-2 rounded px-3 py-2 text-sm font-semibold transition ' +
                                (active
                                    ? 'bg-signal text-white'
                                    : 'text-ink-muted hover:bg-mist hover:text-ink')
                            }
                        >
                            {categories[id]}
                            <span
                                className={
                                    'rounded px-1.5 py-0.5 text-[10px] tabular-nums ' +
                                    (active ? 'bg-white/20' : 'bg-mist')
                                }
                            >
                                {count}
                            </span>
                        </button>
                    );
                })}
            </section>

            <section className="grid gap-3 lg:grid-cols-2">
                {list.map((item) => (
                    <ProviderCard
                        key={`${item.provider}-${editing === item.provider ? 'edit' : 'view'}`}
                        item={item}
                        editing={editing === item.provider}
                        onEdit={() => setEditing(item.provider)}
                        onCancel={() => setEditing(null)}
                        onSaved={() => setEditing(null)}
                    />
                ))}
            </section>
        </div>
    );
}

function ProviderCard({ item, editing, onEdit, onCancel, onSaved }) {
    const initial = useMemo(() => {
        const credentials = {};
        for (const field of item.field_defs || []) {
            if (field.type === 'select') {
                credentials[field.key] =
                    item.fields?.[field.key]?.hint || field.options?.[0]?.value || '';
            } else {
                credentials[field.key] = field.secret ? '' : item.fields?.[field.key]?.hint || '';
            }
        }
        return {
            enabled: item.connected || item.enabled,
            credentials,
        };
    }, [item]);

    const form = useForm(initial);

    const save = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            enabled: !!data.enabled,
            credentials: data.credentials,
        }));
        form.put(route('integrations.update', item.provider), {
            preserveScroll: true,
            onSuccess: () => onSaved(),
        });
    };

    return (
        <div className="atlas-panel flex flex-col p-4">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <div className="flex items-center gap-1">
                        <div className="font-display text-lg font-bold text-ink">{item.label}</div>
                        {PROVIDER_HELP[item.provider] ? (
                            <HelpGuide help={PROVIDER_HELP[item.provider]} />
                        ) : null}
                    </div>
                    <p className="mt-0.5 text-sm text-ink-muted">{item.blurb}</p>
                    {item.redirect_uri ? (
                        <div className="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-2 text-xs text-amber-950">
                            <div className="font-semibold">Google Authorized redirect URI</div>
                            <p className="mt-0.5 text-amber-900/80">
                                Paste this exact URL in Google Cloud Console → OAuth client → Authorized
                                redirect URIs (must match or you get redirect_uri_mismatch).
                            </p>
                            <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                <code className="break-all rounded bg-white px-1.5 py-1 font-mono text-[11px] text-ink">
                                    {item.redirect_uri}
                                </code>
                                <button
                                    type="button"
                                    className="shrink-0 text-[11px] font-bold uppercase tracking-wide text-signal"
                                    onClick={() => navigator.clipboard?.writeText(item.redirect_uri)}
                                >
                                    Copy
                                </button>
                            </div>
                        </div>
                    ) : null}
                </div>
                <span
                    className={
                        'shrink-0 rounded-md px-2 py-0.5 text-[10px] font-bold uppercase ' +
                        (item.connected
                            ? 'bg-emerald-100 text-emerald-800'
                            : item.platform_fallback
                              ? 'bg-sky-100 text-sky-800'
                              : 'bg-mist text-ink-muted')
                    }
                >
                    {item.connected
                        ? 'Connected'
                        : item.platform_fallback
                          ? 'Platform default'
                          : 'Not set'}
                </span>
            </div>

            {!editing ? (
                <>
                    <ul className="mt-3 space-y-1 text-xs text-ink-muted">
                        {(item.field_defs || []).map((field) => {
                            const f = item.fields?.[field.key];
                            return (
                                <li key={field.key} className="flex justify-between gap-2">
                                    <span>{field.label}</span>
                                    <span className="font-semibold text-ink">
                                        {f?.configured ? f.hint || 'Saved' : '—'}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                    {item.last_error ? (
                        <p className="mt-2 text-xs text-rose-600">{item.last_error}</p>
                    ) : null}
                    <div className="mt-4 flex flex-wrap gap-2">
                        <PrimaryButton type="button" onClick={onEdit}>
                            {item.connected ? 'Update' : 'Configure'}
                        </PrimaryButton>
                        {item.connected ? (
                            <SecondaryButton
                                type="button"
                                onClick={() =>
                                    router.post(route('integrations.disconnect', item.provider))
                                }
                            >
                                Disconnect
                            </SecondaryButton>
                        ) : null}
                        {item.id ? (
                            <button
                                type="button"
                                className="text-sm font-semibold text-rose-600"
                                onClick={async () => {
                                    const ok = await confirmAsk({
                                        title: 'Remove provider keys?',
                                        message:
                                            'Saved API keys for this provider will be deleted from the workspace.',
                                        confirmLabel: 'Remove keys',
                                    });
                                    if (ok) {
                                        router.delete(route('integrations.destroy', item.provider));
                                    }
                                }}
                            >
                                Remove keys
                            </button>
                        ) : null}
                    </div>
                </>
            ) : (
                <form className="mt-3 space-y-3" onSubmit={save}>
                    {(item.field_defs || []).map((field) => (
                        <div key={field.key}>
                            <InputLabel
                                value={
                                    field.label +
                                    (field.secret && item.fields?.[field.key]?.configured
                                        ? ' (leave blank to keep)'
                                        : '')
                                }
                            />
                            {field.type === 'select' ? (
                                <div className="mt-1">
                                    <SelectMenu
                                        value={form.data.credentials[field.key] || ''}
                                        onChange={(v) =>
                                            form.setData('credentials', {
                                                ...form.data.credentials,
                                                [field.key]: v,
                                            })
                                        }
                                        options={(field.options || []).map((opt) => ({
                                            value: opt.value,
                                            label: opt.label,
                                        }))}
                                    />
                                </div>
                            ) : (
                                <TextInput
                                    className="mt-1 w-full"
                                    type={field.secret ? 'password' : 'text'}
                                    placeholder={field.placeholder || ''}
                                    value={form.data.credentials[field.key] || ''}
                                    onChange={(e) =>
                                        form.setData('credentials', {
                                            ...form.data.credentials,
                                            [field.key]: e.target.value,
                                        })
                                    }
                                    autoComplete="off"
                                />
                            )}
                        </div>
                    ))}
                    <Toggle
                        checked={!!form.data.enabled}
                        onChange={(on) => form.setData('enabled', on)}
                        label="Enable this provider"
                    />
                    <div className="flex flex-wrap gap-2">
                        <PrimaryButton processing={form.processing}>Save</PrimaryButton>
                        <SecondaryButton type="button" onClick={onCancel}>
                            Cancel
                        </SecondaryButton>
                    </div>
                </form>
            )}
        </div>
    );
}
