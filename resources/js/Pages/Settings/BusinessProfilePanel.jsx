import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import SelectMenu from '@/Components/SelectMenu';
import TextInput from '@/Components/TextInput';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

function emptySocialLinks() {
    return {
        facebook: '',
        instagram: '',
        linkedin: '',
        youtube: '',
        x: '',
        threads: '',
    };
}

function profileFromWorkspace(ws) {
    return {
        business_type: ws?.business_type || '',
        industry: ws?.industry || '',
        tagline: ws?.tagline || '',
        description: ws?.description || '',
        city: ws?.city || '',
        state: ws?.state || '',
        country: ws?.country || 'India',
        postal_code: ws?.postal_code || '',
        address: ws?.address || '',
        phone: ws?.phone || '',
        whatsapp: ws?.whatsapp || '',
        email: ws?.email || '',
        website: ws?.website || '',
        services: ws?.services_text || '',
        products: ws?.products_text || '',
        target_audience: ws?.target_audience || '',
        working_hours: ws?.working_hours || '',
        social_links: { ...emptySocialLinks(), ...(ws?.social_links || {}) },
    };
}

export default function BusinessProfilePanel({
    activeWorkspace,
    businessTypes = [],
}) {
    const profileForm = useForm(profileFromWorkspace(activeWorkspace));
    const canManage =
        activeWorkspace?.role === 'owner' || activeWorkspace?.role === 'admin';

    useEffect(() => {
        profileForm.setData(profileFromWorkspace(activeWorkspace));
        profileForm.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeWorkspace?.id]);

    const setSocial = (key, value) => {
        profileForm.setData('social_links', {
            ...profileForm.data.social_links,
            [key]: value,
        });
    };

    return (
        <section className="atlas-panel overflow-hidden">
            <div className="border-b border-line px-4 py-3.5">
                <h3 className="font-display text-base font-bold text-ink">Business profile</h3>
                <p className="mt-0.5 text-sm text-ink-muted">
                    Company details for{' '}
                    <span className="font-semibold text-ink">
                        {activeWorkspace?.name || 'this workspace'}
                    </span>
                    . Used across AI, documents, cards, and marketing. Logo & colors live in Brand
                    Kit.
                </p>
            </div>
            <div className="p-4">
                {!activeWorkspace ? (
                    <p className="text-sm text-ink-muted">Select a workspace first.</p>
                ) : !canManage ? (
                    <p className="text-sm text-ink-muted">
                        Only owners and admins can edit the business profile.
                    </p>
                ) : (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            profileForm.patch(
                                route('workspaces.profile.update', activeWorkspace.id),
                                { preserveScroll: true },
                            );
                        }}
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Business type *" />
                                <div className="mt-1">
                                    <SelectMenu
                                        value={profileForm.data.business_type}
                                        onChange={(v) => {
                                            profileForm.setData('business_type', v);
                                            if (v !== 'other') {
                                                const label =
                                                    businessTypes.find((o) => o.value === v)
                                                        ?.label || '';
                                                profileForm.setData('industry', label);
                                            }
                                        }}
                                        options={businessTypes}
                                        placeholder="Select business type…"
                                    />
                                </div>
                                <InputError
                                    message={profileForm.errors.business_type}
                                    className="mt-1"
                                />
                            </div>
                            {profileForm.data.business_type === 'other' ? (
                                <div>
                                    <InputLabel value="Custom type *" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="e.g. Wedding planner"
                                        value={profileForm.data.industry}
                                        onChange={(e) =>
                                            profileForm.setData('industry', e.target.value)
                                        }
                                        required
                                    />
                                    <InputError
                                        message={profileForm.errors.industry}
                                        className="mt-1"
                                    />
                                </div>
                            ) : (
                                <div>
                                    <InputLabel value="Tagline" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="Short brand line"
                                        value={profileForm.data.tagline}
                                        onChange={(e) =>
                                            profileForm.setData('tagline', e.target.value)
                                        }
                                    />
                                    <InputError
                                        message={profileForm.errors.tagline}
                                        className="mt-1"
                                    />
                                </div>
                            )}
                            {profileForm.data.business_type === 'other' ? (
                                <div className="sm:col-span-2">
                                    <InputLabel value="Tagline" />
                                    <TextInput
                                        className="mt-1 w-full"
                                        placeholder="Short brand line"
                                        value={profileForm.data.tagline}
                                        onChange={(e) =>
                                            profileForm.setData('tagline', e.target.value)
                                        }
                                    />
                                </div>
                            ) : null}
                            <div className="sm:col-span-2">
                                <InputLabel value="About / description" />
                                <textarea
                                    className="mt-1 w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                                    rows={3}
                                    placeholder="What you offer, who you serve…"
                                    value={profileForm.data.description}
                                    onChange={(e) =>
                                        profileForm.setData('description', e.target.value)
                                    }
                                />
                                <InputError
                                    message={profileForm.errors.description}
                                    className="mt-1"
                                />
                            </div>
                            <div>
                                <InputLabel value="City *" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="Lucknow, Mumbai…"
                                    value={profileForm.data.city}
                                    onChange={(e) => profileForm.setData('city', e.target.value)}
                                    required
                                />
                                <InputError message={profileForm.errors.city} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="State" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="Uttar Pradesh"
                                    value={profileForm.data.state}
                                    onChange={(e) => profileForm.setData('state', e.target.value)}
                                />
                            </div>
                            <div>
                                <InputLabel value="Country" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="India"
                                    value={profileForm.data.country}
                                    onChange={(e) =>
                                        profileForm.setData('country', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <InputLabel value="Postal code" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="226001"
                                    value={profileForm.data.postal_code}
                                    onChange={(e) =>
                                        profileForm.setData('postal_code', e.target.value)
                                    }
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <InputLabel value="Address" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="Street, area…"
                                    value={profileForm.data.address}
                                    onChange={(e) =>
                                        profileForm.setData('address', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <InputLabel value="Phone" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="+91 98XXXX XXXX"
                                    value={profileForm.data.phone}
                                    onChange={(e) => profileForm.setData('phone', e.target.value)}
                                />
                                <InputError message={profileForm.errors.phone} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="WhatsApp" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="+91 98XXXX XXXX"
                                    value={profileForm.data.whatsapp}
                                    onChange={(e) =>
                                        profileForm.setData('whatsapp', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <InputLabel value="Email" />
                                <TextInput
                                    className="mt-1 w-full"
                                    type="email"
                                    placeholder="hello@yourbusiness.com"
                                    value={profileForm.data.email}
                                    onChange={(e) => profileForm.setData('email', e.target.value)}
                                />
                                <InputError message={profileForm.errors.email} className="mt-1" />
                            </div>
                            <div>
                                <InputLabel value="Website" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="https://yourbusiness.com"
                                    value={profileForm.data.website}
                                    onChange={(e) =>
                                        profileForm.setData('website', e.target.value)
                                    }
                                />
                                <InputError
                                    message={profileForm.errors.website}
                                    className="mt-1"
                                />
                            </div>
                            <div>
                                <InputLabel value="Working hours" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="Mon–Sat 10:00–19:00"
                                    value={profileForm.data.working_hours}
                                    onChange={(e) =>
                                        profileForm.setData('working_hours', e.target.value)
                                    }
                                />
                            </div>
                            <div>
                                <InputLabel value="Target audience" />
                                <TextInput
                                    className="mt-1 w-full"
                                    placeholder="Families, SMEs…"
                                    value={profileForm.data.target_audience}
                                    onChange={(e) =>
                                        profileForm.setData('target_audience', e.target.value)
                                    }
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <InputLabel value="Services (one per line)" />
                                <textarea
                                    className="mt-1 w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                                    rows={3}
                                    placeholder="Domestic tours&#10;International tours"
                                    value={profileForm.data.services}
                                    onChange={(e) =>
                                        profileForm.setData('services', e.target.value)
                                    }
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <InputLabel value="Products / packages (one per line)" />
                                <textarea
                                    className="mt-1 w-full rounded-md border-line text-sm shadow-sm focus:border-signal focus:ring-signal"
                                    rows={3}
                                    placeholder="Kashmir 6N&#10;Goa 4N"
                                    value={profileForm.data.products}
                                    onChange={(e) =>
                                        profileForm.setData('products', e.target.value)
                                    }
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <InputLabel value="Social links" />
                                <div className="mt-1 grid gap-2 sm:grid-cols-2">
                                    {[
                                        ['facebook', 'Facebook'],
                                        ['instagram', 'Instagram'],
                                        ['linkedin', 'LinkedIn'],
                                        ['youtube', 'YouTube'],
                                        ['x', 'X / Twitter'],
                                        ['threads', 'Threads'],
                                    ].map(([key, label]) => (
                                        <TextInput
                                            key={key}
                                            className="w-full"
                                            placeholder={label + ' URL'}
                                            value={profileForm.data.social_links?.[key] || ''}
                                            onChange={(e) => setSocial(key, e.target.value)}
                                        />
                                    ))}
                                </div>
                            </div>
                        </div>
                        <PrimaryButton className="mt-4" processing={profileForm.processing}>
                            Save profile
                        </PrimaryButton>
                    </form>
                )}
            </div>
        </section>
    );
}
