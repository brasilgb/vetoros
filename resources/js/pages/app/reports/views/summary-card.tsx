export default function SummaryCard({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 break-inside-avoid">
            <p className="text-[11px] tracking-wide text-slate-500 uppercase">{label}</p>
            <p className="text-sm font-semibold text-slate-900">{value}</p>
        </div>
    );
}
