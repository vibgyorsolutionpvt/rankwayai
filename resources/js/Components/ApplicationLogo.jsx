export default function ApplicationLogo({ className = '', ...props }) {
    return (
        <svg
            {...props}
            className={className}
            viewBox="0 0 40 40"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
            role="img"
            aria-label="rankwayAI"
        >
            <title>rankwayAI</title>
            {/* Ink tile */}
            <rect width="40" height="40" rx="8" fill="#0B1F2A" />
            {/* Rising rank bars */}
            <rect x="8" y="24" width="5" height="8" rx="1.2" fill="#0E9F90" opacity="0.55" />
            <rect x="15.5" y="18" width="5" height="14" rx="1.2" fill="#0E9F90" opacity="0.8" />
            <rect x="23" y="11" width="5" height="21" rx="1.2" fill="#0E9F90" />
            {/* Way / growth path */}
            <path
                d="M9 27.5C14 22 18.5 16.5 27 10.5"
                stroke="#F3F5F8"
                strokeWidth="2.2"
                strokeLinecap="round"
            />
            <path
                d="M23.2 10.2H28.5V15.5"
                stroke="#F3F5F8"
                strokeWidth="2.2"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
            {/* AI node */}
            <circle cx="29.5" cy="9.5" r="2.4" fill="#F3F5F8" />
        </svg>
    );
}
