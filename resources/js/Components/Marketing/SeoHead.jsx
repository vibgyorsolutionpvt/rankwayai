import { Head } from '@inertiajs/react';

export default function SeoHead({ title, description, keywords, canonical, image, jsonLd = null }) {
    return (
        <Head title={title}>
            <meta head-key="description" name="description" content={description} />
            {keywords ? <meta head-key="keywords" name="keywords" content={keywords} /> : null}
            <meta head-key="robots" name="robots" content="index, follow" />
            <link head-key="canonical" rel="canonical" href={canonical} />
            <meta head-key="og:type" property="og:type" content="website" />
            <meta head-key="og:site_name" property="og:site_name" content="rankwayAI" />
            <meta head-key="og:title" property="og:title" content={title} />
            <meta head-key="og:description" property="og:description" content={description} />
            <meta head-key="og:url" property="og:url" content={canonical} />
            {image ? <meta head-key="og:image" property="og:image" content={image} /> : null}
            <meta head-key="twitter:card" name="twitter:card" content="summary_large_image" />
            <meta head-key="twitter:title" name="twitter:title" content={title} />
            <meta head-key="twitter:description" name="twitter:description" content={description} />
            {jsonLd ? (
                <script type="application/ld+json">{JSON.stringify(jsonLd)}</script>
            ) : null}
        </Head>
    );
}
