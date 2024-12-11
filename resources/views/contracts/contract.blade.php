<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Договор</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 13px; /* Updated font size */
            line-height: 1;
            margin: 5px; /* Increased margins */
        }
        .contract {
            max-width: 1200px;
            margin: 0 auto;
            padding: 5px;
        }
        .header {
            text-align: center;
            margin-bottom: 5px;
        }
        .header .contract-number,
        .header .date {
            font-size: 13px; /* Consistent font size */
            font-weight: bold;
            display: inline-block;
        }
        .header .date {
            float: right;
        }
        .section {
            margin-top: 5px;
        }
        .section-title {
            font-weight: bold;
            font-size: 13px;
            text-align: center; /* Centered title */
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="contract">
        <div class="header">
            <div class="contract-number">
                Договор на оказание гостиничных услуг № {{ $contract->number }}
            </div>
            <div class="date">
                Дата: {{ \Carbon\Carbon::parse($contract->date)->format('d/m/Y') }}
            </div>
        </div>

        <p>
            г. Самарканд
        </p>
        <p>
            Гостиница «JAHONGIR» при СП «Jahongir travel», именуемый в дальнейшем «Отель» в лице директора 
            {{ $contract->hotel->director_name }}, действующего на основании Устава, с одной стороны, и {{ $contract->turfirma->official_name }},
            действующего на основании Устава, в лице директора {{ $contract->turfirma->director_name }} с другой стороны, заключили 
            настоящий договор о нижеследующем:
        </p>

        <div class="section">
            <div class="section-title">1. ПРЕДМЕТ ДОГОВОРА</div>
            <p>
                1.1. «Отель» обязуется по поручению Заказчика оказать услуги по размещению и обслуживанию 
                туристов, а Заказчик обязуется оплатить эти услуги.
            </p>
            <p>
                1.2. Цены на оказываемые услуги указаны в Приложении №1, №2 которое является 
                неотъемлемой частью настоящего договора.
            </p>
            <p>
                1.3. Качество услуг должно соответствовать предъявляемым требованиям по оказанию гостиничных услуг.
            </p>
        </div>

        <div class="section">
            <div class="section-title">2. ПРАВА И ОБЯЗАННОСТИ СТОРОН</div>
            <p>2.1.1. Оказать услуги в полном объеме, с надлежащим качеством и в установленные сроки согласно 
            поданной Заказчиком письменной заявке.</p>
            <p>2.1.2. После оказания услуг в 3-х дневной срок предъявить для подписания акт выполненных работ.</p>
            <p>2.2.1. Заблаговременно, не позднее чем за 10 дней подать заявку письменно, по факсу либо 
            по электронной почте на обслуживание туристов, содержащую следующие сведения: указания 
            количества обслуживаемых туристов, перечень услуг, сроки оказания услуг иначе необходимо 
            аннулировать услуги.</p>
            <p>2.2.2. В случае аннулирования заявки, не позднее, чем за 30 дня отозвать ее, направив соответствующее письмо (по факсу или по e-mailу).</p>
            <p>2.2.3. В течение 7 дней со дня подписания акта выполненных работ осуществить полный расчет за выполненные работы на сумму указанную в акте.</p>
            <p>2.3. Заказчик имеет право:</p>
            <p>2.3.1. Контролировать качество оказываемых услуг туристам</p>
        </div>

        <div class="section">
            <div class="section-title">3. ЦЕНА ДОГОВОРА И ПОРЯДОК РАСЧЕТОВ</div>
            <p>
                3.1. Заказчик производит предоплату в размере не менее 15% от общей стоимости 
                услуг, в 3-х дневный срок с момента подачи заявки. Бронирование считается действительным после письменного подтверждения «Исполнителем» запрашиваемых услуг, условия и цены согласно договору. Отель оставляет за собой право отказа при отсутствии мест.
            </p>
            <p>
                3.2. Окончательный расчет осуществляется «Заказчиком» согласно счет-фактуры (акта обслуживания), 
                предоставленной «Отелем». Счет фактура (акт обслуживания) должна быть принята и подписана 
                «Заказчиком».
            </p>
            <p>
                3.3. Цены на предоставляемые услуги могут быть изменены Исполнителем, в случае повышения цен на энергоресурсы, товары и другие материалы, в случае колебания обменного курса иностранной валюты по отношению к национальной валюте в более или менее 5% начиная с обменного курса от 1 января 2024 года о чем составляется дополнительное соглашение, которое будет являться неотъемлемой частью настоящего договора.
            </p>
            <p>
                3.4. «Отель» взимает оплату дополнительных расходов туриста, не указанных в ваучере или в заявке от «Заказчика», непосредственно с туриста.
            </p>
        </div>

        <div class="section">
            <div class="section-title">4. ОТВЕТСТВЕННОСТЬ СТОРОН</div>
            <p>
                4.1. За несвоевременное или ненадлежащее оказание услуг. Отель уплачивает Заказчику пеню из расчета 0,4% суммы договора за каждый день просрочки, но не более 50% стоимости неисполненной части договора.
            </p>
            <p>
                4.2. При не заезде туристов Заказчика без уведомления об аннуляции заявки. Заказчик уплачивает Исполнителю полную сумму стоимости проживания туристов, только в случае гарантированной брони.
            </p>
            <p>
                4.3. При аннуляции заявок штрафные санкции составляют:
                <ul>
                    <li>до 30 дней до заезда туристов - без штрафных санкций;</li>
                    <li>до 20 дней - 15% от суммы заказанных услуг;</li>
                    <li>до 15 дней - 50% от суммы заказанных услуг;</li>
                    <li>до 5 дней - 100% от суммы заказанных услуг;</li>
                </ul>
            </p>
            <p>
                4.4. Отель не несет ответственности за утерю или ущерб личных вещей туристов (багажа, ценностей, денежных средств и пр.) не переданных на хранение в сейф или оставленных без присмотра в коридорах или общественных местах гостиницы.
            </p>
        </div>

        <div class="section">
            <div class="section-title">5. ПОРЯДОК РАЗРЕШЕНИЯ СПОРОВ</div>
            <p>
                5.1. Споры и разногласия, которые могут возникнуть при заключении, исполнении и расторжении настоящего договора, будут по возможности разрешаться путем переговоров между сторонами.
            </p>
            <p>
                5.2. В случае невозможности разрешения споров путем переговоров стороны передают их на рассмотрение Хозяйственного суда по месту нахождения ответчика.
            </p>
        </div>

        <div class="section">
            <div class="section-title">6. СРОК ДЕЙСТВИЯ ДОГОВОРА</div>
            <p>
                6.1. Настоящий Договор вступает в силу с момента подписания и действует до «31» декабря {{ $contract_end_date }} г.
            </p>
            <p>
                6.2. Настоящий Договор подлежит расторжению в одностороннем порядке в случаях грубого нарушения одной из сторон своих обязательств по настоящему Договору или ликвидации предприятия. Сторона, желающая расторгнуть Договор, должна уведомить другую сторону письменно не позднее, чем за одну неделю до предполагаемой даты расторжения.
            </p>
        </div>

        <div class="section" style="font-size: 12px;">
            <div class="section-title">РЕКВИЗИТЫ И ПОДПИСИ СТОРОН</div>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="width: 50%; vertical-align: top; padding: 10px; border-right: 1px solid black;">
                        <strong>{{ $contract->hotel->name }} при {{ $contract->hotel->official_name }}</strong><br>
                        р/с {{ $contract->hotel->account_number }}<br>
                        {{ $contract->hotel->bank_name }} МФО {{ $contract->hotel->bank_mfo }}<br>
                        ИНН {{ $contract->hotel->inn }} <br>
                        {{ $contract->hotel->address }}<br>
                        Тел: {{ $contract->hotel->phone }}<br>
                        Email: {{ $contract->hotel->email }}<br>
                        Директор: {{ $contract->hotel->director_name }}<br>
                    </td>
                    <td style="width: 50%; vertical-align: top; padding: 10px;">
                        <strong>Турфирма:</strong><br>
                        {{ $contract->turfirma->official_name }}<br>
                        р/с: {{ $contract->turfirma->account_number }}<br>
                        ИНН: {{ $contract->turfirma->inn }}<br>
                        {{ $contract->turfirma->bank_name }}<br>
                        МФО: {{ $contract->turfirma->bank_mfo }}<br>
                        {{ $contract->turfirma->address_street }}, {{ $contract->turfirma->address_city }}<br>
                        Тел.: {{ $contract->turfirma->phone }}<br>
                        Директор: {{ $contract->turfirma->director_name }}<br>
                    </td>
                </tr>
                <tr>
                    <td colspan="3" style="text-align: right; padding-top: 130px;">
                        Приложение № 1 к договору № {{ $contract->number }} от {{ \Carbon\Carbon::parse($contract->date)->format('d/m/Y') }}<br>
                        (является неотъемлемой частью договора и без него не действительно)
                    </td>
                </tr>
            </table>
        </div>

        <div class="pricing-table">
            <style>
                .pricing-table {
                    font-family: 'DejaVu Sans', sans-serif;
                    background-color: #fff;
                    padding: 20px;
                    border-radius: 5px;
                    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
                    margin: 20px 0;
                    max-width: 100%;
                    overflow-x: auto;
                }
        
                .pricing-table table {
                    width: 100%;
                    border-collapse: collapse;
                }
        
                .pricing-table th, .pricing-table td {
                    border: 1px solid #000;
                    text-align: center;
                    padding: 8px;
                    font-size: 14px;
                }
        
                .pricing-table th {
                    background-color: #f4f4f4;
                    font-weight: bold;
                }
        
                .pricing-table tr:nth-child(even) {
                    background-color: #f9f9f9;
                }
        
                .pricing-table tr:hover {
                    background-color: #f1f1f1;
                }
            </style>
        
        <table border="1" cellpadding="10" cellspacing="0" style="width: 100%; border-collapse: collapse; text-align: center;">
            <thead>
                <tr style="background-color: #f4f4f4;">
                    <th rowspan="2" style="padding: 8px; vertical-align: middle;">Тип размещения</th>
                    <th colspan="2" style="padding: 8px;">Несезон (С 15.11 по 01.03)</th>
                    <th colspan="2" style="padding: 8px;">Сезон (С 01.03 по 15.11)</th>
                    <th rowspan="2" style="padding: 8px; vertical-align: middle;">Кол-во номеров / макс. вмест. (шт/чел)</th>
                </tr>
                <tr style="background-color: #f4f4f4;">
                    <th style="padding: 8px;">одномест. Разм.</th>
                    <th style="padding: 8px;">двум. Разм.</th>
                    <th style="padding: 8px;">одномест. Разм.</th>
                    <th style="padding: 8px;  width: 90px;">двум. Разм.</th>
                </tr>
            </thead>
            <tbody>
                {{-- Iterate over hotels --}}
                @foreach ($hotelData as $hotelId => $data)
                    <!-- Hotel Header -->
                    <tr style="background-color: #dfe6e9; font-weight: bold;">
                        <td colspan="6" style="padding: 8px;">{{ $data['hotelName'] }}</td>
                    </tr>
        
                    <!-- Room Data Rows -->
                    @foreach ($data['rooms'] as $room)
                        <tr>
                            <td style="padding: 8px; font-size: 11px;">{{ $room->name }}</td>
                            <td style="padding: 8px;">
                                {{ number_format(ceil(($room->discounted_price_as_single_for_hotel['hotel_' . $hotelId] ?? $room->price_as_single) / 1000) * 1000, 0, '.', ' ') }}
                            </td>
                            <td style="padding: 8px;  width: 90px;">
                                {{ number_format(ceil(($room->discounted_price_as_double_for_hotel['hotel_' . $hotelId] ?? $room->price_as_double) / 1000) * 1000, 0, '.', ' ') }}
                            </td>
                            <td style="padding: 8px;">
                                {{ number_format($room->price_as_single, 0, '.', ' ') }}
                            </td>
                            <td style="padding: 8px;">
                                {{ number_format($room->price_as_double, 0, '.', ' ') }}
                            </td>
                            <td style="padding: 8px;">
                                {{ $room->quantity }} / {{ $room->number_of_beds }}
                            </td>
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>
         
        
        
        </div>
        
        

        
        <p>
            <strong>Директор:</strong> {{ $contract->director_signature }}
        </p>
        <p>
            <strong>Заказчик:</strong> {{ $contract->customer_signature }}
        </p>
    </div>
</body>
</html>




