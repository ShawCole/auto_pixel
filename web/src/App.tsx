import { BrowserRouter as Router, Routes, Route } from 'react-router-dom'
import Layout from './components/Layout'
import PixelGeneration from './pages/PixelGeneration'
import AdminPanel from './pages/AdminPanel'

export default function App() {
    return (
        <Router>
            <Layout>
                <Routes>
                    <Route path="/" element={<PixelGeneration />} />
                    <Route path="/admin" element={<AdminPanel />} />
                </Routes>
            </Layout>
        </Router>
    )
} 