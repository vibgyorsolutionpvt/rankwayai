import LegalDocument from '@/Components/Marketing/LegalDocument';

const sections = (email) => [
    {
        id: 'what',
        title: 'What gets deleted',
        body: [
            'Your user profile (name, email, phone, password hash) and your membership in workspaces.',
            'For workspaces you own: brand kits, media, CRM leads, WhatsApp conversations and templates, social posts, business cards, brochures, quotations, SEO sites, and reports.',
            'Access tokens for connected platforms (Meta / Facebook, Instagram, Threads, WhatsApp, Google). We stop using them immediately and remove them from our systems.',
        ],
    },
    {
        id: 'facebook',
        title: 'Remove RankwayAI from Facebook',
        body: [
            'You can also revoke RankwayAI\'s access from Facebook at any time: open Facebook → Settings & privacy → Settings → Business integrations (or Apps and websites), find RankwayAI, and click Remove.',
            'Removing the app stops RankwayAI from accessing your Facebook, Instagram, Threads, or WhatsApp data. To also delete the data we already stored, follow one of the options above.',
        ],
    },
    {
        id: 'timeline',
        title: 'Timeline and retained records',
        body: [
            'We process deletion requests within 30 days of verifying your identity and confirm by email once complete.',
            'We may keep limited records where the law requires it (for example invoices and tax records) or to resolve disputes and prevent fraud. These are kept only as long as necessary and are not used for any other purpose.',
            'Messages you already sent through WhatsApp, or posts already published to Facebook, Instagram, or Threads, live on those platforms and must be deleted there.',
        ],
    },
    {
        id: 'contact',
        title: 'Contact',
        body: [
            `Data deletion questions: ${email}`,
            'Company: Vibgyor Solution — https://vibgyorsolution.com',
        ],
    },
];

export default function DataDeletion({ auth, canLogin, canRegister, seo, contact_email }) {
    const email = contact_email || 'contact@rankwayai.com';

    return (
        <LegalDocument
            auth={auth}
            canLogin={canLogin}
            canRegister={canRegister}
            seo={seo}
            email={email}
            title="User Data Deletion"
            updated="2 October 2026"
            intro="How to delete your RankwayAI account and all data we hold about you, including data received from Facebook, Instagram, Threads, and WhatsApp."
            sections={sections(email)}
        >
            <section id="how">
                <h2 className="font-display text-xl font-bold text-ink">How to request deletion</h2>
                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <div className="rounded-xl border border-line bg-white p-5">
                        <div className="text-xs font-semibold uppercase tracking-[0.14em] text-signal-strong">
                            Option 1 · In the app
                        </div>
                        <ol className="mt-3 list-decimal space-y-1.5 pl-5 text-sm leading-relaxed text-ink-muted">
                            <li>Log in to rankwayai.com</li>
                            <li>Open your Profile</li>
                            <li>Scroll to Delete account</li>
                            <li>Confirm with your password</li>
                        </ol>
                    </div>
                    <div className="rounded-xl border border-line bg-white p-5">
                        <div className="text-xs font-semibold uppercase tracking-[0.14em] text-signal-strong">
                            Option 2 · By email
                        </div>
                        <p className="mt-3 text-sm leading-relaxed text-ink-muted">
                            Email{' '}
                            <a
                                href={`mailto:${email}?subject=Data%20deletion%20request`}
                                className="font-semibold text-signal-strong hover:text-signal"
                            >
                                {email}
                            </a>{' '}
                            with the subject "Data deletion request" from the email address on your
                            account. Include your workspace name if you have more than one.
                        </p>
                    </div>
                </div>
            </section>
        </LegalDocument>
    );
}
