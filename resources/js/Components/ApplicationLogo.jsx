/**
 * App mark — square RankwayAI icon (favicon artwork).
 * Full wordmark lives at /img/rankwayai-logo.png
 */
export default function ApplicationLogo({ className = '', alt = 'RankwayAI', ...props }) {
    return (
        <img
            {...props}
            src="/img/rankwayai-icon.png?v=3"
            alt={alt}
            className={`object-contain ${className}`}
            decoding="async"
        />
    );
}
