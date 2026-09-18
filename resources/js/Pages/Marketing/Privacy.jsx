import BrandName from '@/Components/BrandName';
import MarketingLayout from '@/Layouts/MarketingLayout';
import { Link } from '@inertiajs/react';

const SECTIONS = [
    {
        id: 'who',
        title: '1. Who we are',
        body: [
            'RankwayAI (rankwayai.com) is a marketing operating system that helps businesses and agencies run SEO, Google Search Console insights, PageSpeed fixes, social publishing, WhatsApp messaging, and CRM in one workspace.',
            'RankwayAI is a product of Vibgyor Solution. For privacy requests, contact us at contact@rankwayai.com.',
        ],
    },
    {
        id: 'scope',
        title: '2. Scope',
        body: [
            'This Privacy Policy explains how we collect, use, store, and share information when you visit rankwayai.com, create an account, or use the RankwayAI platform (including WhatsApp Business / Meta Cloud API features we operate on your behalf).',
            'By using RankwayAI, you agree to this Policy. If you do not agree, please do not use the service.',
        ],
    },
    {
        id: 'collect',
        title: '3. Information we collect',
        body: [
            'Account & profile: name, email address, password (hashed), phone number if you provide it, and workspace / company details.',
            'Business & brand data: brand kits, websites, contact details, media you upload, CRM leads, templates, campaigns, and content you create in the product.',
            'Messaging data: WhatsApp / channel phone numbers, message content, delivery status, and related metadata needed to send and receive messages through connected providers (including Meta).',
            'Integrations: credentials and tokens you connect (for example Meta WhatsApp, Google Search Console, social accounts, SMTP). Tokens are stored securely and used only to provide the connected feature.',
            'Usage & technical data: IP address, browser/device info, log data, approximate location derived from IP, and product analytics needed to operate and secure the service.',
            'Billing: plan, invoices, and payment references processed by our payment provider (we do not store full card numbers on our servers).',
            'Contact form: name, email, company, and message when you contact us.',
        ],
    },
    {
        id: 'use',
        title: '4. How we use information',
        body: [
            'Provide, maintain, and improve RankwayAI features (SEO, social, WhatsApp, CRM, billing, support).',
            'Authenticate users, manage workspaces and team access, and prevent abuse or fraud.',
            'Send service emails (account, security, billing, product notices). Marketing emails only where permitted and with an unsubscribe option where required.',
            'Connect to third-party platforms you authorize (Meta, Google, social networks, email SMTP, payment gateways) to perform actions you request.',
            'Comply with law, enforce terms, and protect the rights and safety of users and Vibgyor Solution.',
        ],
    },
    {
        id: 'meta',
        title: '5. WhatsApp, Meta, and other processors',
        body: [
            'If you use WhatsApp Business features, message content and phone numbers are processed through Meta’s WhatsApp Business Platform (Cloud API) and related Meta services under Meta’s terms and policies, in addition to this Policy.',
            'When we act as a Tech Provider / platform for your business, we process WhatsApp Business Account data as needed to connect, send, and receive messages for your workspace. You remain responsible for lawful messaging, consent, and template content toward your end customers.',
            'Other processors may include cloud hosting, email delivery, analytics, and payment providers. They process data only under contracts and for the purposes above.',
        ],
    },
    {
        id: 'share',
        title: '6. When we share information',
        body: [
            'With service providers who help us run RankwayAI (hosting, email, payments, error monitoring).',
            'With platforms you connect (Meta, Google, social networks) to fulfill your requests.',
            'With your workspace members according to roles you assign.',
            'If required by law, regulation, legal process, or to protect rights and safety.',
            'In connection with a merger, acquisition, or asset transfer, with notice where required.',
            'We do not sell your personal information.',
        ],
    },
    {
        id: 'retention',
        title: '7. Retention',
        body: [
            'We keep account and workspace data while your account is active and as needed to provide the service.',
            'After deletion or closure, we may retain limited records for legal, tax, dispute, and security purposes for a reasonable period, then delete or anonymize them where feasible.',
            'Message logs and integration credentials follow workspace settings and operational needs; you may request deletion of your account data as described below.',
        ],
    },
    {
        id: 'security',
        title: '8. Security',
        body: [
            'We use industry-standard measures such as encryption in transit (HTTPS), access controls, and hashed passwords.',
            'No method of transmission or storage is 100% secure. Please use a strong password and protect your login and API tokens.',
        ],
    },
    {
        id: 'rights',
        title: '9. Your choices and rights',
        body: [
            'You can access and update profile and workspace settings in the product.',
            'You may request access, correction, or deletion of personal data we hold about you, subject to legal exceptions, by emailing contact@rankwayai.com.',
            'You may disconnect integrations and revoke third-party tokens from Settings / Integrations where available.',
            'If you are in India, you may have rights under applicable data protection law (including the Digital Personal Data Protection Act, 2023, as it applies). We will respond to verified requests within a reasonable time.',
        ],
    },
    {
        id: 'children',
        title: '10. Children',
        body: [
            'RankwayAI is built for businesses and professionals. It is not directed at children under 18. We do not knowingly collect personal data from children.',
        ],
    },
    {
        id: 'intl',
        title: '11. International processing',
        body: [
            'We primarily serve customers in India. Data may be processed on servers or by providers in India or other countries where our infrastructure or partners operate. Where we transfer data, we take steps appropriate to the nature of the transfer and applicable law.',
        ],
    },
    {
        id: 'changes',
        title: '12. Changes to this Policy',
        body: [
            'We may update this Privacy Policy from time to time. The “Last updated” date at the top will change when we do. Continued use after changes means you accept the updated Policy. Material changes may also be notified in-product or by email.',
        ],
    },
    {
        id: 'contact',
        title: '13. Contact',
        body: [
            'Privacy questions or requests: contact@rankwayai.com',
            'Product: RankwayAI — https://rankwayai.com',
            'Company: Vibgyor Solution — https://vibgyorsolution.com',
        ],
    },
];

export default function Privacy({ auth, canLogin, canRegister, seo, contact_email }) {
    const email = contact_email || 'contact@rankwayai.com';

    return (
        <MarketingLayout
            seo={seo}
            auth={auth}
            canLogin={canLogin}
            canRegister={canRegister}
            jsonLd={{
                '@context': 'https://schema.org',
                '@type': 'WebPage',
                name: seo?.title,
                description: seo?.description,
                url: seo?.canonical,
            }}
        >
            <section className="relative overflow-hidden border-b border-line">
                <div className="absolute inset-0 bg-[radial-gradient(ellipse_80%_60%_at_0%_0%,rgba(14,159,144,0.16),transparent_55%)]" />
                <div className="relative mx-auto max-w-3xl px-6 py-16 sm:py-20">
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-signal-strong">
                        Legal
                    </p>
                    <h1 className="mt-4 font-display text-4xl font-bold tracking-tight text-ink sm:text-5xl">
                        Privacy Policy
                    </h1>
                    <p className="mt-3 text-sm text-ink-muted">Last updated: 18 September 2026</p>
                    <p className="mt-5 text-base leading-relaxed text-ink-muted">
                        How <BrandName className="inline text-ink" /> collects and uses information
                        when you use our website and platform.
                    </p>
                </div>
            </section>

            <article className="mx-auto max-w-3xl space-y-10 px-6 py-14 sm:py-16">
                {SECTIONS.map((section) => (
                    <section key={section.id} id={section.id}>
                        <h2 className="font-display text-xl font-bold text-ink">{section.title}</h2>
                        <div className="mt-3 space-y-3 text-sm leading-relaxed text-ink-muted">
                            {section.body.map((para) => (
                                <p key={para}>
                                    {section.id === 'contact' && para.startsWith('Privacy')
                                        ? para.replace('contact@rankwayai.com', email)
                                        : para}
                                </p>
                            ))}
                        </div>
                    </section>
                ))}

                <p className="border-t border-line pt-8 text-sm text-ink-muted">
                    Questions?{' '}
                    <a
                        href={`mailto:${email}`}
                        className="font-semibold text-signal-strong hover:text-signal"
                    >
                        {email}
                    </a>{' '}
                    or visit our{' '}
                    <Link href={route('contact')} className="font-semibold text-ink underline">
                        Contact
                    </Link>{' '}
                    page.
                </p>
            </article>
        </MarketingLayout>
    );
}
