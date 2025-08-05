import { useState, useEffect } from 'react'
import { Calendar, Trash2, Search, AlertCircle } from 'lucide-react'

interface Pixel {
    id: string
    clientName: string
    website: string
    sheetUrl?: string
    createdAt: string
    industry?: string
    eventCount: number
    visitorCount: number
    deletionScheduled?: string
}

export default function AdminPanel() {
    const [pixels, setPixels] = useState<Pixel[]>([])
    const [filteredPixels, setFilteredPixels] = useState<Pixel[]>([])
    const [isLoading, setIsLoading] = useState(true)
    const [error, setError] = useState<string | null>(null)
    const [searchTerm, setSearchTerm] = useState('')
    const [industryFilter, setIndustryFilter] = useState('all')
    const [sortBy, setSortBy] = useState<'date' | 'name' | 'events'>('date')
    const [selectedPixels, setSelectedPixels] = useState<Set<string>>(new Set())
    const [showDeleteConfirm, setShowDeleteConfirm] = useState(false)
    const [showAdvancedDeleteDialog, setShowAdvancedDeleteDialog] = useState(false)
    const [deletingPixel, setDeletingPixel] = useState<string | null>(null)
    const [deleteStep, setDeleteStep] = useState<'confirm' | 'downloading' | 'deleting' | 'complete'>('confirm')
    const [deleteProgress, setDeleteProgress] = useState<string>('')
    const [tooltip, setTooltip] = useState<{ show: boolean; content: string; x: number; y: number }>({
        show: false,
        content: '',
        x: 0,
        y: 0
    })

    // Mock data - replace with actual API call
    useEffect(() => {
        const fetchPixels = async () => {
            try {
                setIsLoading(true)
                const apiUrl = import.meta.env.VITE_API_URL ||
                    (window.location.hostname === 'localhost' ? 'http://localhost:4000' : 'https://api.thynkdata.com')

                const res = await fetch(`${apiUrl}/admin/pixels`)
                if (!res.ok) {
                    throw new Error('Failed to fetch pixels')
                }

                const data = await res.json()
                setPixels(data.pixels || [])
                setFilteredPixels(data.pixels || [])
            } catch (err: any) {
                setError(err.message || 'Failed to fetch pixels')
            } finally {
                setIsLoading(false)
            }
        }

        fetchPixels()
    }, [])

    // Filter and sort pixels
    useEffect(() => {
        let filtered = [...pixels]

        // Search filter
        if (searchTerm) {
            filtered = filtered.filter(pixel =>
                pixel.clientName.toLowerCase().includes(searchTerm.toLowerCase()) ||
                pixel.website.toLowerCase().includes(searchTerm.toLowerCase())
            )
        }

        // Industry filter
        if (industryFilter !== 'all') {
            filtered = filtered.filter(pixel => pixel.industry === industryFilter)
        }

        // Sort
        filtered.sort((a, b) => {
            switch (sortBy) {
                case 'date':
                    return new Date(b.createdAt).getTime() - new Date(a.createdAt).getTime()
                case 'name':
                    return a.clientName.localeCompare(b.clientName)
                case 'events':
                    return b.eventCount - a.eventCount
                default:
                    return 0
            }
        })

        setFilteredPixels(filtered)
    }, [pixels, searchTerm, industryFilter, sortBy])

    const handleDelete = async (pixelIds: string[]) => {
        try {
            const apiUrl = import.meta.env.VITE_API_URL ||
                (window.location.hostname === 'localhost' ? 'http://localhost:4000' : 'https://api.thynkdata.com')

            const res = await fetch(`${apiUrl}/admin/pixels/delete`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ pixelIds })
            })

            if (!res.ok) {
                throw new Error('Failed to delete pixels')
            }

            // Remove from local state
            setPixels(prev => prev.filter(p => !pixelIds.includes(p.id)))
            setSelectedPixels(new Set())
            setShowDeleteConfirm(false)
        } catch (err: any) {
            setError(err.message || 'Failed to delete pixels')
        }
    }

    const handleAdvancedDelete = async (pixelId: string, action: 'save-and-delete' | 'delete-only' | 'cancel') => {
        if (action === 'cancel') {
            setShowAdvancedDeleteDialog(false)
            setDeletingPixel(null)
            setDeleteStep('confirm')
            setDeleteProgress('')
            return
        }

        setDeletingPixel(pixelId)
        setShowAdvancedDeleteDialog(true)
        setDeleteStep('confirm')

        try {
            const apiUrl = import.meta.env.VITE_API_URL ||
                (window.location.hostname === 'localhost' ? 'http://localhost:4000' : 'https://api.thynkdata.com')

            if (action === 'save-and-delete') {
                // Step 1: Download data
                setDeleteStep('downloading')
                setDeleteProgress('Downloading client data...')

                const downloadRes = await fetch(`${apiUrl}/admin/pixels/${pixelId}/download`)
                if (!downloadRes.ok) {
                    throw new Error('Failed to download client data')
                }

                const downloadData = await downloadRes.json()

                // Create and download file
                const blob = new Blob([JSON.stringify(downloadData.data, null, 2)], { type: 'application/json' })
                const url = window.URL.createObjectURL(blob)
                const a = document.createElement('a')
                a.href = url
                a.download = `${downloadData.data.clientName}_data_${new Date().toISOString().split('T')[0]}.json`
                document.body.appendChild(a)
                a.click()
                document.body.removeChild(a)
                window.URL.revokeObjectURL(url)
            }

            // Step 2: Delete from SimpleAudience
            setDeleteStep('deleting')
            setDeleteProgress('Deleting pixel from SimpleAudience...')

            const simpleAudienceRes = await fetch(`${apiUrl}/admin/pixels/${pixelId}/delete-from-simpleaudience`, {
                method: 'POST'
            })

            if (!simpleAudienceRes.ok) {
                throw new Error('Failed to delete pixel from SimpleAudience')
            }

            // Step 3: Delete from database
            setDeleteProgress('Deleting client data from database...')

            const databaseRes = await fetch(`${apiUrl}/admin/pixels/${pixelId}/delete-from-database`, {
                method: 'POST'
            })

            if (!databaseRes.ok) {
                throw new Error('Failed to delete client from database')
            }

            // Step 4: Complete
            setDeleteStep('complete')
            setDeleteProgress('Deletion completed successfully!')

            // Remove from local state
            setPixels(prev => prev.filter(p => p.id !== pixelId))

            // Close dialog after 2 seconds
            setTimeout(() => {
                setShowAdvancedDeleteDialog(false)
                setDeletingPixel(null)
                setDeleteStep('confirm')
                setDeleteProgress('')
            }, 2000)

        } catch (err: any) {
            setError(err.message || 'Failed to delete pixel')
            setDeleteStep('confirm')
            setDeleteProgress('')
        }
    }

    const toggleSelectPixel = (pixelId: string) => {
        setSelectedPixels(prev => {
            const newSet = new Set(prev)
            if (newSet.has(pixelId)) {
                newSet.delete(pixelId)
            } else {
                newSet.add(pixelId)
            }
            return newSet
        })
    }

    const selectAllPixels = () => {
        if (selectedPixels.size === filteredPixels.length) {
            setSelectedPixels(new Set())
        } else {
            setSelectedPixels(new Set(filteredPixels.map(p => p.id)))
        }
    }

    const industries = ['all', ...Array.from(new Set(pixels.map(p => p.industry).filter(Boolean)))]

    // Hide tooltip on scroll
    useEffect(() => {
        const handleScroll = () => {
            setTooltip({ show: false, content: '', x: 0, y: 0 })
        }

        window.addEventListener('scroll', handleScroll, true)
        return () => window.removeEventListener('scroll', handleScroll, true)
    }, [])



    return (
        <div className="p-8">
            <div className="max-w-7xl mx-auto">
                {/* Header */}
                <div className="mb-8">
                    <h1 className="text-3xl font-bold text-gray-900 mb-2">Admin Panel</h1>
                    <p className="text-gray-600">Manage all active pixels and their data</p>
                </div>

                {/* Stats Overview */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div className="bg-white rounded-lg shadow p-6">
                        <div className="text-sm font-medium text-gray-500 mb-1">Total Pixels</div>
                        <div className="text-2xl font-bold text-gray-900">{pixels.length}</div>
                    </div>
                    <div className="bg-white rounded-lg shadow p-6">
                        <div className="text-sm font-medium text-gray-500 mb-1">Total Events</div>
                        <div className="text-2xl font-bold text-gray-900">
                            {pixels.reduce((sum, p) => sum + p.eventCount, 0).toLocaleString()}
                        </div>
                    </div>
                    <div className="bg-white rounded-lg shadow p-6">
                        <div className="text-sm font-medium text-gray-500 mb-1">Total Visitors</div>
                        <div className="text-2xl font-bold text-gray-900">
                            {pixels.reduce((sum, p) => sum + p.visitorCount, 0).toLocaleString()}
                        </div>
                    </div>
                    <div className="bg-white rounded-lg shadow p-6">
                        <div className="text-sm font-medium text-gray-500 mb-1">Industries</div>
                        <div className="text-2xl font-bold text-gray-900">{industries.length - 1}</div>
                    </div>
                </div>

                {/* Filters and Actions */}
                <div className="bg-white rounded-lg shadow mb-6">
                    <div className="p-6 border-b">
                        <div className="flex flex-col md:flex-row gap-4">
                            {/* Search */}
                            <div className="flex-1">
                                <div className="relative">
                                    <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-5 h-5" />
                                    <input
                                        type="text"
                                        placeholder="Search by client name or website..."
                                        className="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                        value={searchTerm}
                                        onChange={(e) => setSearchTerm(e.target.value)}
                                    />
                                </div>
                            </div>

                            {/* Industry Filter */}
                            <div className="w-full md:w-48">
                                <select
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    value={industryFilter}
                                    onChange={(e) => setIndustryFilter(e.target.value)}
                                >
                                    {industries.map(industry => (
                                        <option key={industry} value={industry}>
                                            {industry === 'all' ? 'All Industries' : industry}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/* Sort */}
                            <div className="w-full md:w-48">
                                <select
                                    className="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                                    value={sortBy}
                                    onChange={(e) => setSortBy(e.target.value as any)}
                                >
                                    <option value="date">Sort by Date</option>
                                    <option value="name">Sort by Name</option>
                                    <option value="events">Sort by Events</option>
                                </select>
                            </div>

                            {/* Delete Selected */}
                            {selectedPixels.size > 0 && (
                                <button
                                    onClick={() => setShowDeleteConfirm(true)}
                                    className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors flex items-center gap-2"
                                >
                                    <Trash2 className="w-4 h-4" />
                                    Delete ({selectedPixels.size})
                                </button>
                            )}
                        </div>
                    </div>

                    {/* Pixels Table */}
                    <div className="overflow-x-auto">
                        <table className="w-full table-fixed">
                            <thead>
                                <tr className="border-b bg-gray-50">
                                    <th className="p-4 text-left" style={{ width: '50px' }}>
                                        <input
                                            type="checkbox"
                                            checked={selectedPixels.size === filteredPixels.length && filteredPixels.length > 0}
                                            onChange={selectAllPixels}
                                            className="rounded border-gray-300"
                                        />
                                    </th>
                                    <th className="p-4 text-left text-sm font-medium text-gray-700" style={{ width: '180px' }}>Client</th>
                                    <th className="p-4 text-left text-sm font-medium text-gray-700" style={{ width: '200px' }}>Website</th>
                                    <th className="p-4 text-left text-sm font-medium text-gray-700" style={{ width: '120px' }}>Industry</th>
                                    <th className="p-4 text-left text-sm font-medium text-gray-700" style={{ width: '80px' }}>Events</th>
                                    <th className="p-4 text-left text-sm font-medium text-gray-700" style={{ width: '80px' }}>Visitors</th>
                                    <th className="p-4 text-left text-sm font-medium text-gray-700" style={{ width: '120px' }}>Created</th>
                                    <th className="p-4 text-left text-sm font-medium text-gray-700" style={{ width: '140px' }}>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {isLoading ? (
                                    <tr>
                                        <td colSpan={8} className="p-8 text-center text-gray-500">
                                            Loading pixels...
                                        </td>
                                    </tr>
                                ) : filteredPixels.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="p-8 text-center text-gray-500">
                                            No pixels found
                                        </td>
                                    </tr>
                                ) : (
                                    filteredPixels.map(pixel => (
                                        <tr key={pixel.id} className="border-b hover:bg-gray-50">
                                            <td className="p-4" style={{ width: '50px' }}>
                                                <input
                                                    type="checkbox"
                                                    checked={selectedPixels.has(pixel.id)}
                                                    onChange={() => toggleSelectPixel(pixel.id)}
                                                    className="rounded border-gray-300"
                                                />
                                            </td>
                                            <td className="p-4" style={{ width: '180px' }}>
                                                <div className="font-medium text-gray-900 truncate">{pixel.clientName}</div>
                                                {pixel.deletionScheduled && (
                                                    <div className="text-xs text-red-600 mt-1 flex items-center gap-1">
                                                        <AlertCircle className="w-3 h-3" />
                                                        Deletion scheduled
                                                    </div>
                                                )}
                                            </td>
                                            <td
                                                className="p-4 text-sm text-gray-600"
                                                style={{ width: '200px' }}
                                                onMouseEnter={() => {
                                                    const textElement = document.querySelector(`#website-${pixel.id}`)
                                                    if (textElement && textElement.scrollWidth > textElement.clientWidth) {
                                                        const rect = textElement.getBoundingClientRect()
                                                        setTooltip({
                                                            show: true,
                                                            content: pixel.website,
                                                            x: rect.left,
                                                            y: rect.top
                                                        })
                                                    }
                                                }}
                                                onMouseLeave={() => {
                                                    setTooltip({ show: false, content: '', x: 0, y: 0 })
                                                }}
                                            >
                                                <div
                                                    id={`website-${pixel.id}`}
                                                    className="truncate"
                                                >
                                                    {pixel.website}
                                                </div>
                                            </td>
                                            <td className="p-4" style={{ width: '120px' }}>
                                                <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 truncate">
                                                    {pixel.industry || 'Uncategorized'}
                                                </span>
                                            </td>
                                            <td className="p-4 text-sm text-gray-900" style={{ width: '80px' }}>{pixel.eventCount.toLocaleString()}</td>
                                            <td className="p-4 text-sm text-gray-900" style={{ width: '80px' }}>{pixel.visitorCount.toLocaleString()}</td>
                                            <td className="p-4 text-sm text-gray-600" style={{ width: '120px' }}>
                                                <div className="flex items-center gap-1 truncate">
                                                    <Calendar className="w-4 h-4" />
                                                    {new Date(pixel.createdAt).toLocaleDateString()}
                                                </div>
                                            </td>
                                            <td className="p-4" style={{ width: '140px' }}>
                                                <div className="flex items-center gap-2">
                                                    {pixel.sheetUrl && (
                                                        <a
                                                            href={pixel.sheetUrl}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="text-blue-600 hover:text-blue-800 text-sm font-medium"
                                                        >
                                                            View Sheet
                                                        </a>
                                                    )}
                                                    <button
                                                        onClick={() => {
                                                            setDeletingPixel(pixel.id)
                                                            setShowAdvancedDeleteDialog(true)
                                                        }}
                                                        className="text-red-600 hover:text-red-800 text-sm font-medium"
                                                    >
                                                        Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Delete Confirmation Modal */}
                {showDeleteConfirm && (
                    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
                        <div className="bg-white rounded-lg max-w-md w-full p-6">
                            <h3 className="text-lg font-semibold text-gray-900 mb-2">Confirm Deletion</h3>
                            <p className="text-gray-600 mb-4">
                                Are you sure you want to delete {selectedPixels.size} pixel{selectedPixels.size > 1 ? 's' : ''}?
                                This will schedule the data for deletion after 30 days.
                            </p>
                            <div className="flex gap-3 justify-end">
                                <button
                                    onClick={() => setShowDeleteConfirm(false)}
                                    className="px-4 py-2 border border-gray-300 rounded-lg hover:bg-gray-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    onClick={() => handleDelete(Array.from(selectedPixels))}
                                    className="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700"
                                >
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {/* Advanced Delete Dialog */}
                {showAdvancedDeleteDialog && deletingPixel && (
                    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
                        <div className="bg-white rounded-lg max-w-lg w-full p-6">
                            {deleteStep === 'confirm' && (
                                <>
                                    <h3 className="text-lg font-semibold text-gray-900 mb-2">Delete Pixel</h3>
                                    <p className="text-gray-600 mb-4">
                                        This will permanently delete the pixel from SimpleAudience and remove all associated data.
                                        Choose an option:
                                    </p>
                                    <div className="space-y-3">
                                        <button
                                            onClick={() => handleAdvancedDelete(deletingPixel, 'save-and-delete')}
                                            className="w-full px-4 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 flex items-center justify-center gap-2"
                                        >
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                            Save & Delete
                                        </button>
                                        <button
                                            onClick={() => handleAdvancedDelete(deletingPixel, 'delete-only')}
                                            className="w-full px-4 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 flex items-center justify-center gap-2"
                                        >
                                            <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                            Delete w/o Saving
                                        </button>
                                        <button
                                            onClick={() => handleAdvancedDelete(deletingPixel, 'cancel')}
                                            className="w-full px-4 py-3 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </>
                            )}

                            {(deleteStep === 'downloading' || deleteStep === 'deleting') && (
                                <>
                                    <h3 className="text-lg font-semibold text-gray-900 mb-2">Processing...</h3>
                                    <div className="flex items-center justify-center mb-4">
                                        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
                                    </div>
                                    <p className="text-gray-600 text-center">{deleteProgress}</p>
                                </>
                            )}

                            {deleteStep === 'complete' && (
                                <>
                                    <h3 className="text-lg font-semibold text-green-900 mb-2">Success!</h3>
                                    <div className="flex items-center justify-center mb-4">
                                        <svg className="w-12 h-12 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                        </svg>
                                    </div>
                                    <p className="text-gray-600 text-center">{deleteProgress}</p>
                                </>
                            )}
                        </div>
                    </div>
                )}

                {/* Error Display */}
                {error && (
                    <div className="fixed bottom-4 right-4 bg-red-50 border border-red-200 rounded-lg p-4 max-w-md">
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

                {/* Custom Tooltip Overlay */}
                {tooltip.show && (
                    <div
                        className="fixed z-50 bg-gray-900 text-white text-sm px-3 py-2 rounded-lg shadow-lg pointer-events-none"
                        style={{
                            left: tooltip.x,
                            top: tooltip.y - 40,
                            maxWidth: '300px',
                            wordBreak: 'break-all'
                        }}
                    >
                        {tooltip.content}
                    </div>
                )}
            </div>
        </div>
    )
} 