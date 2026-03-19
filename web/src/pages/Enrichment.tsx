import { useState } from 'react'
import { Search, List, Upload, Loader2, CheckCircle, AlertCircle } from 'lucide-react'

export default function Enrichment() {
    const [activeTab, setActiveTab] = useState<'enrich' | 'list' | 'create'>('enrich')

    return (
        <div className="p-8 max-w-7xl mx-auto">
            <div className="mb-8">
                <h1 className="text-3xl font-bold text-gray-900 mb-2">Data Enrichment</h1>
                <p className="text-gray-600">Enrich your contacts, view past jobs, and process bulk records.</p>
            </div>

            {/* Tabs */}
            <div className="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
                <div className="flex border-b border-gray-200">
                    <button
                        onClick={() => setActiveTab('enrich')}
                        className={`flex items-center space-x-2 px-6 py-4 text-sm font-medium transition-colors ${activeTab === 'enrich'
                            ? 'bg-blue-50 text-blue-600 border-b-2 border-blue-600'
                            : 'text-gray-600 hover:bg-gray-50'
                            }`}
                    >
                        <Search className="w-4 h-4" />
                        <span>Enrich Contact</span>
                    </button>
                    <button
                        onClick={() => setActiveTab('list')}
                        className={`flex items-center space-x-2 px-6 py-4 text-sm font-medium transition-colors ${activeTab === 'list'
                            ? 'bg-blue-50 text-blue-600 border-b-2 border-blue-600'
                            : 'text-gray-600 hover:bg-gray-50'
                            }`}
                    >
                        <List className="w-4 h-4" />
                        <span>Get Enrichments</span>
                    </button>
                    <button
                        onClick={() => setActiveTab('create')}
                        className={`flex items-center space-x-2 px-6 py-4 text-sm font-medium transition-colors ${activeTab === 'create'
                            ? 'bg-blue-50 text-blue-600 border-b-2 border-blue-600'
                            : 'text-gray-600 hover:bg-gray-50'
                            }`}
                    >
                        <Upload className="w-4 h-4" />
                        <span>Create Enrichment Job</span>
                    </button>
                </div>

                <div className="p-6">
                    {activeTab === 'enrich' && <EnrichContact />}
                    {activeTab === 'list' && <GetEnrichments />}
                    {activeTab === 'create' && <CreateEnrichmentJob />}
                </div>
            </div>
        </div>
    )
}

function EnrichContact() {
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState<string | null>(null)
    const [result, setResult] = useState<any | null>(null)

    // Form state with explicit fields from requirements
    const [formData, setFormData] = useState({
        first_name: '',
        last_name: '',
        email: '',
        phone: '',
        company_name: '',
        personal_city: '',
        company_domain: ''
    })

    const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        setFormData(prev => ({ ...prev, [e.target.name]: e.target.value }))
    }

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault()
        setLoading(true)
        setError(null)
        setResult(null)

        // Filter out empty strings to send only populated fields
        const filter = Object.fromEntries(
            Object.entries(formData).filter(([_, v]) => v.trim() !== '')
        )

        if (Object.keys(filter).length === 0) {
            setError("Please provide at least one search criteria.")
            setLoading(false)
            return
        }

        try {
            // Use production API URL if no environment variable is set and we're not on localhost
            const apiUrl = import.meta.env.VITE_API_URL ||
                (window.location.hostname === 'localhost' ? 'http://localhost:4000' : 'https://api.thynkdata.com')

            const payload = {
                filter,
                is_or_match: false
            };
            console.log("Sending Enrichment Request:", payload);

            const response = await fetch(`${apiUrl}/enrich`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            })

            const data = await response.json()
            console.log("Enrichment API Result:", data);

            if (!response.ok) {
                throw new Error(data.message || 'Failed to enrich contact')
            }

            setResult(data)
        } catch (err: any) {
            setError(err.message || 'An unexpected error occurred')
        } finally {
            setLoading(false)
        }
    }

    return (
        <div className="max-w-4xl">
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
                {/* Inputs */}
                <div className="space-y-6">
                    <h3 className="text-lg font-medium text-gray-900">Search Criteria</h3>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                                <input
                                    name="first_name"
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="John"
                                    value={formData.first_name}
                                    onChange={handleChange}
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                                <input
                                    name="last_name"
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Doe"
                                    value={formData.last_name}
                                    onChange={handleChange}
                                />
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Email Address</label>
                            <input
                                name="email"
                                type="email"
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="john.doe@example.com"
                                value={formData.email}
                                onChange={handleChange}
                            />
                        </div>

                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">Phone Number</label>
                            <input
                                name="phone"
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="555-123-4567"
                                value={formData.phone}
                                onChange={handleChange}
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Company Name</label>
                                <input
                                    name="company_name"
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Acme Corp"
                                    value={formData.company_name}
                                    onChange={handleChange}
                                />
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-gray-700 mb-1">Company Domain</label>
                                <input
                                    name="company_domain"
                                    className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="acme.com"
                                    value={formData.company_domain}
                                    onChange={handleChange}
                                />
                            </div>
                        </div>
                        <div>
                            <label className="block text-sm font-medium text-gray-700 mb-1">City</label>
                            <input
                                name="personal_city"
                                className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="San Francisco"
                                value={formData.personal_city}
                                onChange={handleChange}
                            />
                        </div>

                        <div className="pt-2">
                            <button
                                type="submit"
                                disabled={loading}
                                className="w-full bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white font-semibold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center space-x-2"
                            >
                                {loading ? (
                                    <>
                                        <Loader2 className="w-5 h-5 animate-spin" />
                                        <span>Enriching...</span>
                                    </>
                                ) : (
                                    <span>Enrich Contact</span>
                                )}
                            </button>
                        </div>
                        {error && (
                            <div className="p-3 bg-red-50 text-red-700 border border-red-200 rounded-lg text-sm flex items-start space-x-2">
                                <AlertCircle className="w-5 h-5 flex-shrink-0" />
                                <span>{error}</span>
                            </div>
                        )}
                    </form>
                </div>

                {/* Results */}
                <div className="bg-gray-50 rounded-xl border border-gray-200 p-6 min-h-[400px]">
                    <h3 className="text-lg font-medium text-gray-900 mb-4">Enrichment Result</h3>

                    {!result && !loading && (
                        <div className="h-full flex flex-col items-center justify-center text-gray-400">
                            <Search className="w-12 h-12 mb-3 opacity-20" />
                            <p>Enter search criteria to see enriched data</p>
                        </div>
                    )}

                    {loading && (
                        <div className="h-full flex flex-col items-center justify-center text-gray-400">
                            <Loader2 className="w-12 h-12 mb-3 animate-spin text-blue-500" />
                            <p>Searching database...</p>
                        </div>
                    )}

                    {result && (
                        <div className="space-y-4 animate-in fade-in duration-300">
                            <div className="flex items-center space-x-2 text-green-600 bg-green-50 p-3 rounded-lg border border-green-100 mb-4">
                                <CheckCircle className="w-5 h-5" />
                                <span className="font-medium">Found {result.found} records</span>
                            </div>

                            {result.result?.map((record: any, idx: number) => (
                                <div key={idx} className="bg-white p-4 rounded-lg shadow-sm border border-gray-200 space-y-3">
                                    <div className="flex justify-between items-start">
                                        <div>
                                            <h4 className="font-bold text-lg text-gray-900">{record.first_name} {record.last_name}</h4>
                                            <p className="text-gray-500 text-sm">{record.job_title} at {record.company_name}</p>
                                        </div>
                                        {record.linkedin_url && (
                                            <a href={record.linkedin_url} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:text-blue-800 text-sm">LinkedIn ↗</a>
                                        )}
                                    </div>

                                    <div className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                        <div>
                                            <span className="text-gray-500 block text-xs">Email</span>
                                            <span className="font-medium">{record.email || record.business_email || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-gray-500 block text-xs">Location</span>
                                            <span className="font-medium">{[record.city, record.state].filter(Boolean).join(', ') || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-gray-500 block text-xs">Industry</span>
                                            <span className="font-medium">{record.industry || '-'}</span>
                                        </div>
                                        <div>
                                            <span className="text-gray-500 block text-xs">Domain</span>
                                            <span className="font-medium">{record.company_domain || '-'}</span>
                                        </div>
                                    </div>
                                </div>
                            ))}
                            {result.found === 0 && (
                                <div className="text-center py-8 text-gray-500">
                                    No matches found for these criteria.
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </div>
    )
}

function GetEnrichments() {
    // Mock data for display purposes
    const jobs = [
        { id: 'job_123', status: 'Completed', timestamp: '2024-01-15 10:30 AM', records: 154, success_rate: '98%' },
        { id: 'job_124', status: 'Processing', timestamp: '2024-01-15 11:45 AM', records: 500, success_rate: '-' },
        { id: 'job_122', status: 'Failed', timestamp: '2024-01-14 02:15 PM', records: 50, success_rate: '0%' },
    ]

    return (
        <div className="space-y-6">
            <div className="flex justify-between items-center">
                <h3 className="text-lg font-medium text-gray-900">Enrichment Jobs History</h3>
                <button className="text-blue-600 text-sm font-medium hover:text-blue-800">Refresh List</button>
            </div>

            <div className="bg-white rounded-lg border border-gray-200 overflow-hidden">
                <table className="min-w-full divide-y divide-gray-200">
                    <thead className="bg-gray-50">
                        <tr>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Job ID</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Records</th>
                            <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Match Rate</th>
                        </tr>
                    </thead>
                    <tbody className="bg-white divide-y divide-gray-200">
                        {jobs.map((job) => (
                            <tr key={job.id}>
                                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{job.id}</td>
                                <td className="px-6 py-4 whitespace-nowrap">
                                    <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        ${job.status === 'Completed' ? 'bg-green-100 text-green-800' : ''}
                                        ${job.status === 'Processing' ? 'bg-yellow-100 text-yellow-800' : ''}
                                        ${job.status === 'Failed' ? 'bg-red-100 text-red-800' : ''}
                                    `}>
                                        {job.status}
                                    </span>
                                </td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{job.timestamp}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{job.records}</td>
                                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{job.success_rate}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="flex justify-center text-sm text-gray-500 pt-4">
                Showing 3 recent jobs (Mock Data)
            </div>
        </div>
    )
}

function CreateEnrichmentJob() {
    return (
        <div className="max-w-2xl mx-auto py-8">
            <div className="border-2 border-dashed border-gray-300 rounded-xl p-12 text-center hover:border-blue-500 hover:bg-blue-50 transition-colors cursor-pointer">
                <Upload className="mx-auto h-12 w-12 text-gray-400" />
                <h3 className="mt-2 text-sm font-semibold text-gray-900">Upload CSV file</h3>
                <p className="mt-1 text-sm text-gray-500">Drag and drop or click to upload contact list</p>
                <p className="mt-4 text-xs text-gray-400">Supported formats: CSV, XLSX up to 10MB</p>
            </div>

            <div className="mt-8">
                <h4 className="font-medium text-gray-900 mb-2">Instructions</h4>
                <ul className="list-disc list-inside text-sm text-gray-600 space-y-1">
                    <li>File must contain a header row</li>
                    <li>Required columns: First Name, Last Name, Domain (or Company)</li>
                    <li>Make sure your file is UTF-8 encoded</li>
                </ul>
            </div>

            <div className="mt-8">
                <button disabled className="w-full bg-gray-300 text-white font-semibold py-3 px-4 rounded-lg cursor-not-allowed">
                    Start Enrichment Job (Coming Soon)
                </button>
            </div>
        </div>
    )
}
