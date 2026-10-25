import BrochureView from '@/Components/Studio/BrochureView';
import { Head } from '@inertiajs/react';

export default function Public({ brochure, track_url, pdf_url }) {
    return (
        <>
            <Head title={brochure.title || brochure.headline || 'Brochure'} />
            <BrochureView
                brochure={brochure}
                sections={brochure.enabled_sections}
                track_url={track_url}
                pdf_url={pdf_url}
            />
        </>
    );
}
