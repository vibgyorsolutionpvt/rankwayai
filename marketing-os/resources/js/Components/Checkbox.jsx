export default function Checkbox({ className = '', ...props }) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-line text-signal shadow-none focus:ring-signal/30 ' +
                className
            }
        />
    );
}
