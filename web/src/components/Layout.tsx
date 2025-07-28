import { ReactNode } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { Settings, LayoutGrid } from 'lucide-react'

interface LayoutProps {
    children: ReactNode
}

export default function Layout({ children }: LayoutProps) {
    const location = useLocation()

    const isActive = (path: string) => location.pathname === path

    return (
        <div className="flex h-screen bg-gray-50">
            {/* Sidebar */}
            <div className="w-64 bg-white shadow-lg">
                <div className="flex flex-col h-full">
                    {/* Logo/Brand */}
                    <div className="px-6 py-6 border-b">
                        <h2 className="text-2xl font-bold text-gray-800">THYNKdata</h2>
                    </div>

                    {/* Navigation */}
                    <nav className="flex-1 px-4 py-6">
                        <Link
                            to="/"
                            className={`flex items-center space-x-3 px-4 py-3 rounded-lg transition-colors ${isActive('/')
                                ? 'bg-blue-50 text-blue-600'
                                : 'text-gray-700 hover:bg-gray-100'
                                }`}
                        >
                            <LayoutGrid className="w-5 h-5" />
                            <span className="font-medium">Pixel Generation</span>
                        </Link>
                    </nav>

                    {/* Bottom Settings */}
                    <div className="px-4 py-6 border-t">
                        <Link
                            to="/admin"
                            className={`flex items-center space-x-3 px-4 py-3 rounded-lg transition-colors ${isActive('/admin')
                                ? 'bg-blue-50 text-blue-600'
                                : 'text-gray-700 hover:bg-gray-100'
                                }`}
                        >
                            <Settings className="w-5 h-5" />
                            <span className="font-medium">Admin Panel</span>
                        </Link>
                    </div>
                </div>
            </div>

            {/* Main Content */}
            <div className="flex-1 overflow-auto">
                {children}
            </div>
        </div>
    )
} 