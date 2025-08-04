import { useState } from 'react'

export default function PixelGeneration() {
    const [error, setError] = useState<string | null>(null)
    const [isLoading, setIsLoading] = useState(false)
    const [clientName, setClientName] = useState('')
    const [websiteUrl, setWebsiteUrl] = useState('')
    const [pixelCode, setPixelCode] = useState('')
    const [sheetUrl, setSheetUrl] = useState('')

    const formatWebsiteUrl = (url: string) => {
        let formatted = url.trim()
        // Only add https:// if no protocol is specified, otherwise leave URL exactly as entered
        if (!/^https?:\/\//i.test(formatted)) {
            formatted = 'https://' + formatted
        }
        return formatted
    }
    const handleSubmit = async () => {
        setError(null)
        setPixelCode('')
        setSheetUrl('')

        if (!clientName.trim()) {
            setError('Client name is required')
            return
        }

        if (!clientName.match(/^[_a-zA-Z0-9]+$/)) {
            setError('Client name can only contain letters, numbers, and underscores (no hyphens)')
            return
        }

        if (!websiteUrl.trim()) {
            setError('Website URL is required')
            return
        }

        const formattedUrl = formatWebsiteUrl(websiteUrl)

        setIsLoading(true)

        try {
            // Use production API URL if no environment variable is set and we're not on localhost
            const apiUrl = import.meta.env.VITE_API_URL ||
                (window.location.hostname === 'localhost' ? 'http://localhost:4000' : 'https://api.thynkdata.com')
            const res = await fetch(`${apiUrl}/generate`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ client: clientName, website: formattedUrl }),
            })

            const data = await res.json()

            if (!res.ok) {
                throw new Error(data.error || 'Unknown error occurred')
            }

            if (data.pixelSnippet) {
                setPixelCode(data.pixelSnippet)
                if (data.sheetUrl) {
                    setSheetUrl(data.sheetUrl)
                }
            } else {
                setError(data.error || 'Unknown error')
            }
        } catch (err: any) {
            setError(err.message || 'Failed to generate pixel')
        } finally {
            setIsLoading(false)
        }
    }

    const copyToClipboard = async (text: string, label: string) => {
        try {
            await navigator.clipboard.writeText(text)
            alert(`${label} copied to clipboard!`)
        } catch (err) {
            console.error('Failed to copy:', err)
        }
    }

    return (
        <div className="min-h-screen bg-gradient-to-br from-blue-50 to-indigo-100 flex items-center justify-center p-4">
            <div className="w-full max-w-4xl bg-white rounded-2xl shadow-2xl p-8 space-y-8">
                {/* Header */}
                <div className="text-center">
                    <h1 className="text-3xl font-bold text-gray-900 mb-2">
                        Pixel & Webhook Generator
                    </h1>
                    <p className="text-gray-600">
                        Automatically create pixels, databases, and webhooks for your clients
                    </p>
                </div>

                {/* Input Section */}
                <div className="space-y-4">
                    <div>
                        <label htmlFor="client" className="block text-sm font-medium text-gray-700 mb-2">
                            Client Name
                        </label>
                        <input
                            id="client"
                            type="text"
                            className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg"
                            placeholder="Enter client slug (e.g. strategy_simple)"
                            value={clientName}
                            onChange={(e) => setClientName(e.target.value)}
                            disabled={isLoading}
                        />
                        <p className="mt-1 text-sm text-gray-500">
                            Only letters, numbers, hyphens, and underscores allowed
                        </p>
                    </div>

                    <div>
                        <label htmlFor="websiteUrl" className="block text-sm font-medium text-gray-700 mb-2">
                            Website URL
                        </label>
                        <input
                            id="websiteUrl"
                            type="text"
                            className="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-lg"
                            placeholder="https://example.com"
                            value={websiteUrl}
                            onChange={(e) => setWebsiteUrl(e.target.value)}
                            disabled={isLoading}
                        />
                        <p className="mt-1 text-sm text-gray-500">
                            Please enter a valid website URL
                        </p>
                    </div>

                    <button
                        onClick={handleSubmit}
                        disabled={isLoading || !clientName.trim() || !websiteUrl.trim()}
                        className="w-full bg-blue-600 hover:bg-blue-700 disabled:bg-gray-400 text-white font-semibold py-4 px-6 rounded-xl text-lg transition-colors duration-200 flex items-center justify-center space-x-2"
                    >
                        {isLoading ? (
                            <>
                                <div className="animate-spin rounded-full h-5 w-5 border-b-2 border-white"></div>
                                <span>Generating Pixel...</span>
                            </>
                        ) : (
                            <span>Generate Pixel</span>
                        )}
                    </button>
                </div>

                {/* Error Display */}
                {error && (
                    <div className="bg-red-50 border border-red-200 rounded-lg p-4">
                        <div className="flex">
                            <div className="flex-shrink-0">
                                <svg className="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clipRule="evenodd" />
                                </svg>
                            </div>
                            <div className="ml-3">
                                <h3 className="text-sm font-medium text-red-800">Error</h3>
                                <div className="mt-2 text-sm text-red-700">{error}</div>
                            </div>
                        </div>
                    </div>
                )}

                {/* Success Response */}
                {pixelCode && (
                    <div className="space-y-6">
                        <div className="bg-green-50 border border-green-200 rounded-lg p-4">
                            <div className="flex">
                                <div className="flex-shrink-0">
                                    <svg className="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                                    </svg>
                                </div>
                                <div className="ml-3">
                                    <h3 className="text-sm font-medium text-green-800">Success!</h3>
                                    <div className="mt-2 text-sm text-green-700">Pixel code generated successfully!</div>
                                </div>
                            </div>
                        </div>

                        {/* Google Sheet Link */}
                        {sheetUrl && (
                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <h3 className="text-lg font-semibold text-gray-900">Google Sheet</h3>
                                    <button
                                        onClick={() => copyToClipboard(sheetUrl, 'Sheet URL')}
                                        className="text-blue-600 hover:text-blue-800 text-sm font-medium"
                                    >
                                        Copy URL
                                    </button>
                                </div>
                                <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                                    <a
                                        href={sheetUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-blue-600 hover:text-blue-800 underline break-all"
                                    >
                                        {sheetUrl}
                                    </a>
                                </div>
                                <p className="text-sm text-gray-600">
                                    View and share visitor data with your client. Data syncs every 5 minutes.
                                </p>
                            </div>
                        )}

                        {/* Pixel Script */}
                        <div className="space-y-2">
                            <div className="flex items-center justify-between">
                                <h3 className="text-lg font-semibold text-gray-900">Pixel Script</h3>
                                <button
                                    onClick={() => copyToClipboard(`<!-- THYNKdata Pixel Code -->${pixelCode}<!-- End THYNKdata Pixel Code Pixel Code -->`, 'Pixel Script')}
                                    className="text-blue-600 hover:text-blue-800 text-sm font-medium"
                                >
                                    Copy
                                </button>
                            </div>
                            <div className="bg-gray-50 border border-gray-200 rounded-lg p-4 max-h-96 overflow-y-auto">
                                <pre className="text-sm text-gray-800 whitespace-pre-wrap">{'<!-- THYNKdata Pixel Code -->' + pixelCode + '<!-- End THYNKdata Pixel Code Pixel Code -->'}</pre>
                            </div>
                            <p className="text-sm text-gray-600">
                                Add this script to your client's website just before the closing &lt;/head&gt; tag.
                            </p>
                        </div>
                    </div>
                )}
            </div>
        </div>
    )
} 
