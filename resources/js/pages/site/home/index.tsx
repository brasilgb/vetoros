import { Head } from '@inertiajs/react';
import { AudienceStrip } from '../components/audience-strip';
import { CookieConsent } from '../components/cookie-consent';
import { CTA } from '../components/cta';
import { FAQ, faqSchema } from '../components/faq';
import { Features } from '../components/features';
import { Footer } from '../components/footer';
import { Header } from '../components/header';
import { Hero } from '../components/hero';
import { Testimonials, type TestimonialItem } from '../components/testimonials';
import { WebChat } from '../components/web-chat';

export default function Home({ testimonials = [] }: { testimonials?: TestimonialItem[] }) {
    return (
        <main className="ab-public-site min-h-screen bg-white text-slate-900 selection:bg-blue-100 selection:text-blue-950">
            <Head title="Sistema de ordem de serviço para assistência técnica">
                <meta name="theme-color" content="#ffffff" />
                <script type="application/ld+json">{JSON.stringify(faqSchema)}</script>
            </Head>
            <Header />
            <Hero />
            <AudienceStrip />
            <Features />
            <Testimonials testimonials={testimonials} />
            <FAQ />
            <CTA />
            <Footer />
            <WebChat />
            <CookieConsent />
        </main>
    );
}
