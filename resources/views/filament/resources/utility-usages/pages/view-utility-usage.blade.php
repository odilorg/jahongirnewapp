<style>
    .custom-invoice-wrapper {
        padding: 20px;
    }

    .custom-invoice {
        font-family: Arial, sans-serif;
        line-height: 1.5;
        font-size: 14px;
        color: #333;
        background-color: #fff;
        border: 1px solid #ddd;
        border-radius: 5px;
        padding: 20px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }

    .custom-invoice .table {
        width: 100%;
        margin-bottom: 1rem;
        border-collapse: collapse;
    }

    .custom-invoice .table-bordered {
        border: 1px solid #ddd;
    }

    .custom-invoice .table-bordered th,
    .custom-invoice .table-bordered td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
    }

    .custom-invoice .card-header {
        text-align: center;
        font-weight: bold;
        margin-bottom: 20px;
        font-size: 18px;
    }

    .custom-invoice .no-print {
        margin-top: 20px;
    }

    @media print {
        /* Hide everything except the custom-invoice-wrapper */
        body * {
            visibility: hidden;
        }

        .custom-invoice-wrapper {
            visibility: visible;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
        }

        .custom-invoice-wrapper * {
            visibility: visible;
        }

        .custom-invoice .no-print {
            display: none;
        }
    }
</style>


<div class="custom-invoice-wrapper">
    <div class="custom-invoice">
        <div class="container-fluid">
            <div class="row">
                <div class="col-12" id="invoice">
                    <!-- Original Invoice Content -->
                    <div class="invoice p-3 mb-3">
                        <div class="row invoice-info">
                            <div class="col-12 table-responsive">
                                <table class="table table-sm table-borderless">
                                    <tbody>
                                        <tr><td>Мижоз:</td><td>СП "JAXONGIR TRAVEL"</td></tr>
                                        <tr><td>Шартнома:</td><td>C-II-93 46042 sana 01/01/2022</td></tr>
                                        <tr><td>Х/р:</td><td>20208000704734557001</td></tr>
                                        <tr><td>Банк номи:</td><td>САМАРКАНД Ш., Хамкорбанк Андижон ф-ли</td></tr>
                                        <tr><td>Банк коди:</td><td>00083</td></tr>
                                        <tr><td>ИНН:</td><td>300965341</td></tr>
                                        <tr><td>Манзил:</td><td>Samarkand CHIROQCHI, 4</td></tr>
                                        <tr><td>Тел:</td><td>915550808</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <h3>Сарфланган Tabiiy Gaz буйича хисобот</h3>
                                <h3>Ноябр учун</h3>
                            </div>
                            <div class="card-body table-responsive p-0">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Объектни номи ва манзили</th>
                                            <th>Курсатгич завод раками</th>
                                            <th>Олдинги курсатгич</th>
                                            <th>Охирги курсатгич</th>
                                            <th>Фарки</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Samarkand CHIROQCHI, 4</td>
                                            <td>TPGR036120253481</td>
                                            <td>32224</td>
                                            <td>32908</td>
                                            <td>684</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="table-borderless">
                            <table>
                                <tbody>
                                    <tr>
                                        <td>СП "JAXONGIR TRAVEL"</td>
                                        <td style="text-align:right;">Tabiiy Gaz</td>
                                    </tr>
                                    <tr>
                                        <td>Жавобгар шахс:</td>
                                        <td style="text-align:right;">Кабул килувчи:</td>
                                    </tr>
                                    <tr>
                                        <td>Имзо_____________________</td>
                                        <td style="text-align:right;">Имзо_____________________</td>
                                    </tr>
                                    <tr>
                                        <td>Сана: 25/11/2024</td>
                                        <td style="text-align:right;">Сана: 25/11/2024</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <br>
                    <hr style="height:5px;border-width:0;color:gray;background-color:gray">
                    <!-- Duplicate Invoice Content -->
                    <br>
                    <div class="invoice p-3 mb-3">
                        <div class="row invoice-info">
                            <div class="col-12 table-responsive">
                                <table class="table table-sm table-borderless">
                                    <tbody>
                                        <tr><td>Мижоз:</td><td>СП "JAXONGIR TRAVEL"</td></tr>
                                        <tr><td>Шартнома:</td><td>C-II-93 46042 sana 01/01/2022</td></tr>
                                        <tr><td>Х/р:</td><td>20208000704734557001</td></tr>
                                        <tr><td>Банк номи:</td><td>САМАРКАНД Ш., Хамкорбанк Андижон ф-ли</td></tr>
                                        <tr><td>Банк коди:</td><td>00083</td></tr>
                                        <tr><td>ИНН:</td><td>300965341</td></tr>
                                        <tr><td>Манзил:</td><td>Samarkand CHIROQCHI, 4</td></tr>
                                        <tr><td>Тел:</td><td>915550808</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <h3>Сарфланган Tabiiy Gaz буйича хисобот</h3>
                                <h3>Ноябр учун</h3>
                            </div>
                            <div class="card-body table-responsive p-0">
                                <table class="table table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Объектни номи ва манзили</th>
                                            <th>Курсатгич завод раками</th>
                                            <th>Олдинги курсатгич</th>
                                            <th>Охирги курсатгич</th>
                                            <th>Фарки</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Samarkand CHIROQCHI, 4</td>
                                            <td>TPGR036120253481</td>
                                            <td>32224</td>
                                            <td>32908</td>
                                            <td>684</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="table-borderless">
                            <table>
                                <tbody>
                                    <tr>
                                        <td>СП "JAXONGIR TRAVEL"</td>
                                        <td style="text-align:right;">Tabiiy Gaz</td>
                                    </tr>
                                    <tr>
                                        <td>Жавобгар шахс:</td>
                                        <td style="text-align:right;">Кабул килувчи:</td>
                                    </tr>
                                    <tr>
                                        <td>Имзо_____________________</td>
                                        <td style="text-align:right;">Имзо_____________________</td>
                                    </tr>
                                    <tr>
                                        <td>Сана: 25/11/2024</td>
                                        <td style="text-align:right;">Сана: 25/11/2024</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>



