/**
 * Full RankwayAI wordmark logo (icon + text).
 */
export default function BrandLogo({ className = '', alt = 'RankwayAI', ...props }) {
    return (
        <img
            {...props}
            src="/img/rankwayai-logo.png?v=3"
            alt={alt}
            className={`object-contain ${className}`}
            decoding="async"
        />
    );
}
