import BrandName from '@/Components/BrandName';
import ContactChannels from '@/Components/Marketing/ContactChannels';
import PricingPlans from '@/Components/Marketing/PricingPlans';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import TextInput from '@/Components/TextInput';
import MarketingLayout from '@/Layouts/MarketingLayout';
import { Link, useForm, usePage } from '@inertiajs/react';

export default function Contact({
    auth,
    canLogin,
    canRegister,
    seo,
    contact_email,
    contact_phone,
    plans = [],
}) {
    const { flash } = usePage().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        company: '',
        message: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('contact.store'), {
            onSuccess: () => reset('message'),
        });
    };

    const registerHref = auth?.user ? route('home') : route('register');

    return (
        <MarketingLayout
            seo={seo}
            auth={auth}
            canLogin={canLogin}
            canRegister={canRegister}
            jsonLd={{
                '@context': 'https://schema.org',
                '@type': 'ContactPage',
                name: seo?.title,
                description: seo?.description,
                url: seo?.canonical,
            }}
        >
            <section className="relative overflow-hidden border-b border-line">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_70%_50%_at_100%_0%,rgba(14,159,144,0.14),transparent_50%)]" />
                <div className="relative mx-auto max-w-6xl px-6 py-16 sm:py-20">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        Contact us
                    </p>
                    <h1 className="mt-4 font-display text-3xl font-bold tracking-tight text-ink sm:text-4xl">
                        Talk to <BrandName className="text-inherit" />
                    </h1>
                    <p className="mt-4 max-w-xl text-base leading-relaxed text-ink-muted">
                        Questions about onboarding, agency seats, or a walkthrough — send a note,
                        email, or call us directly.
                    </p>

                    <div className="mt-10">
                        <p className="mb-4 text-xs font-semibold uppercase tracking-[0.2em] text-ink-muted">
                            Reach us directly
                        </p>
                        <ContactChannels email={contact_email} phone={contact_phone} />
                    </div>
                </div>
            </section>

            <section className="mx-auto grid max-w-6xl gap-14 px-6 py-14 lg:grid-cols-2 lg:py-20">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        Send a message
                    </p>
                    <h2 className="mt-3 font-display text-2xl font-bold text-ink">
                        Tell us what you need
                    </h2>
                    <p className="mt-2 text-sm text-ink-muted">
                        Share a bit of context and we will get back with next steps.
                    </p>

                    <form onSubmit={submit} className="mt-8 space-y-5">
                        {flash?.success ? (
                            <div className="rounded-md bg-signal/10 px-4 py-3 text-sm font-medium text-signal-strong">
                                {flash.success}
                            </div>
                        ) : null}

                        <div>
                            <InputLabel htmlFor="name" value="Name" />
                            <TextInput
                                id="name"
                                className="mt-1 block w-full"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                            />
                            <InputError message={errors.name} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="email" value="Email" />
                            <TextInput
                                id="email"
                                type="email"
                                className="mt-1 block w-full"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                required
                            />
                            <InputError message={errors.email} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="company" value="Company (optional)" />
                            <TextInput
                                id="company"
                                className="mt-1 block w-full"
                                value={data.company}
                                onChange={(e) => setData('company', e.target.value)}
                            />
                            <InputError message={errors.company} className="mt-2" />
                        </div>
                        <div>
                            <InputLabel htmlFor="message" value="Message" />
                            <textarea
                                id="message"
                                rows={5}
                                className="mt-1 block w-full rounded-md border-line bg-white shadow-sm focus:border-signal focus:ring-signal"
                                value={data.message}
                                onChange={(e) => setData('message', e.target.value)}
                                required
                            />
                            <InputError message={errors.message} className="mt-2" />
                        </div>
                        <PrimaryButton disabled={processing}>Send message</PrimaryButton>
                    </form>
                </div>

                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        Pricing
                    </p>
                    <h2 className="mt-3 font-display text-2xl font-bold text-ink">
                        Plans at a glance
                    </h2>
                    <p className="mt-2 text-sm text-ink-muted">
                        INR monthly list prices. Yearly saves two months — see full details on{' '}
                        <Link href={route('pricing')} className="font-semibold text-signal-strong">
                            Pricing
                        </Link>
                        .
                    </p>
                    <PricingPlans
                        plans={plans}
                        interval="month"
                        interactive={false}
                        registerHref={registerHref}
                        compact
                    />
                </div>
            </section>
        </MarketingLayout>
    );
}
