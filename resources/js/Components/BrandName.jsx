/**
 * rankwayAI wordmark — “Rankway” + accent “AI”.
 */
export default function BrandName({ className = '', accentClassName = 'text-signal' }) {
    return (
        <span className={`font-display font-bold tracking-tight ${className}`}>
            Rankway
            <span className={accentClassName}>AI</span>
        </span>
    );
}
