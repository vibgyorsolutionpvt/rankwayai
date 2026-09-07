import React from 'react';

export default class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null, errorInfo: null };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error, errorInfo) {
        console.error('Application render error caught by ErrorBoundary:', error, errorInfo);
        this.setState({ error, errorInfo });
    }

    render() {
        if (this.state.hasError) {
            return (
                <div className="flex min-h-screen items-center justify-center bg-mist p-6">
                    <div className="w-full max-w-xl rounded-2xl border border-line bg-white p-6 shadow-xl">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-500/10 text-rose-600">
                                <svg className="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                    <circle cx="12" cy="12" r="10" />
                                    <line x1="12" y1="8" x2="12" y2="12" />
                                    <line x1="12" y1="16" x2="12.01" y2="16" />
                                </svg>
                            </div>
                            <div>
                                <h2 className="font-display text-lg font-bold text-ink">
                                    Something went wrong
                                </h2>
                                <p className="text-xs text-ink-muted">
                                    An unexpected rendering error occurred. Please try reloading or navigating back.
                                </p>
                            </div>
                        </div>

                        {this.state.error ? (
                            <div className="mt-4 rounded-lg border border-rose-200 bg-rose-50/60 p-3 text-xs text-rose-900 font-mono overflow-x-auto">
                                <div className="font-bold">{String(this.state.error?.message || this.state.error)}</div>
                                {this.state.error?.stack ? (
                                    <pre className="mt-2 text-[11px] text-rose-700/80 whitespace-pre-wrap max-h-48 overflow-y-auto">
                                        {this.state.error.stack}
                                    </pre>
                                ) : null}
                            </div>
                        ) : null}

                        <div className="mt-5 flex items-center justify-end gap-3">
                            <button
                                type="button"
                                onClick={() => {
                                    this.setState({ hasError: false, error: null, errorInfo: null });
                                    window.location.href = '/today';
                                }}
                                className="rounded-lg border border-line bg-white px-4 py-2 text-xs font-semibold text-ink shadow-xs hover:border-signal/50"
                            >
                                Go to Today
                            </button>
                            <button
                                type="button"
                                onClick={() => window.location.reload()}
                                className="rounded-lg border border-signal bg-signal px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-signal-strong"
                            >
                                Reload page
                            </button>
                        </div>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}
