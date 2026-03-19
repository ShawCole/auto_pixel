import { BrowserRouter as Router, Routes, Route } from 'react-router-dom'
import Layout from './components/Layout'
import PixelGeneration from './pages/PixelGeneration'
import AdminPanel from './pages/AdminPanel'
import Enrichment from './pages/Enrichment'

export default function App() {
    return (
        <Router>
            <Layout>
                <Routes>
                    <Route path="/" element={<PixelGeneration />} />
                    <Route path="/enrichment" element={<Enrichment />} />
                    <Route path="/admin" element={<AdminPanel />} />
                </Routes>
            </Layout>
        </Router>
    )
} 