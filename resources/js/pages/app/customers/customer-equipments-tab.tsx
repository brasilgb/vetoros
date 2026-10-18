import ActionDelete from '@/components/action-delete';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import CustomerEquipmentForm, { CustomerEquipment } from './customer-equipment-form';

export default function CustomerEquipmentsTab({
    customerId,
    equipments,
    equipmentTypes,
}: {
    customerId: number;
    equipments: CustomerEquipment[];
    equipmentTypes: { id: number; equipment: string }[];
}) {
    return (
        <div className="space-y-4">
            <div className="flex justify-end">
                <CustomerEquipmentForm customerId={customerId} equipmentTypes={equipmentTypes} />
            </div>

            <div className="rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-[80px]">#</TableHead>
                            <TableHead>Marca / Modelo</TableHead>
                            <TableHead>Série / IMEI</TableHead>
                            <TableHead className="w-[100px]">Status</TableHead>
                            <TableHead className="min-w-[120px]"></TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {equipments.length ? (
                            equipments.map((equipment) => (
                                <TableRow key={equipment.id}>
                                    <TableCell>{equipment.customer_equipment_number}</TableCell>
                                    <TableCell className="font-medium">
                                        {[equipment.brand, equipment.model].filter(Boolean).join(' ') || '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-sm">
                                        {[equipment.serial_number, equipment.imei].filter(Boolean).join(' · ') || '—'}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant={equipment.status === 'active' ? 'default' : 'secondary'}>
                                            {equipment.status === 'active' ? 'Ativo' : 'Inativo'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="min-w-[120px]">
                                        <div className="flex flex-wrap justify-end gap-2">
                                            <CustomerEquipmentForm customerId={customerId} equipmentTypes={equipmentTypes} equipment={equipment} />
                                            <ActionDelete title="este equipamento" url="app.customer-equipments.destroy" param={equipment.id} />
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell colSpan={5} className="h-16 text-center">
                                    Nenhum equipamento cadastrado para este cliente.
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
