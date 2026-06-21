<?php
$current_page = basename($_SERVER['PHP_SELF']);
$user = getCurrentUser();
$user_role = $user['role'] ?? 'guest';
?>

<style>
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        width: 280px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        transition: all 0.3s;
        z-index: 1000;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        overflow-y: auto;
    }
    .sidebar.collapsed { width: 70px; }
    .sidebar.collapsed .sidebar-header h3, .sidebar.collapsed .sidebar-header p,
    .sidebar.collapsed .user-info .user-name, .sidebar.collapsed .user-info .user-role,
    .sidebar.collapsed .nav-link span, .sidebar.collapsed .nav-title,
    .sidebar.collapsed .nav-link .badge { display: none; }
    .sidebar.collapsed .nav-link i { margin-right: 0; font-size: 20px; }
    .sidebar.collapsed .nav-item { text-align: center; }
    .sidebar.collapsed .user-info .user-icon i { font-size: 30px; }
    .sidebar.collapsed .dropdown-menu-custom { display: none !important; }
    .sidebar::-webkit-scrollbar { width: 5px; }
    .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
    .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 5px; }
    
    .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.2); margin-bottom: 20px; position: relative; }
    .toggle-btn { position: absolute; right: 10px; top: 20px; background: rgba(255,255,255,0.2); border: none; color: white; border-radius: 5px; padding: 5px 10px; cursor: pointer; }
    .sidebar-header h3 { margin: 0; font-size: 20px; font-weight: bold; }
    .sidebar-header p { margin: 5px 0 0; font-size: 12px; opacity: 0.8; }
    
    .user-info { padding: 15px 20px; background: rgba(255,255,255,0.1); margin: 0 15px 20px 15px; border-radius: 10px; text-align: center; }
    .user-info .user-icon { font-size: 40px; margin-bottom: 5px; }
    .user-info .user-name { font-weight: bold; font-size: 16px; }
    .user-info .user-role { font-size: 11px; opacity: 0.8; text-transform: uppercase; }
    
    .nav-menu { list-style: none; padding: 0; margin: 0; }
    .nav-item { margin: 5px 15px; border-radius: 10px; transition: all 0.3s; }
    .nav-item:hover { background: rgba(255,255,255,0.1); }
    .nav-item.active { background: rgba(255,255,255,0.2); box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
    .nav-link { display: flex; align-items: center; padding: 12px 15px; color: white; text-decoration: none; font-size: 14px; transition: all 0.3s; cursor: pointer; }
    .nav-link:hover { color: white; text-decoration: none; transform: translateX(5px); }
    .nav-link i { width: 25px; margin-right: 10px; font-size: 18px; }
    .nav-link .badge { margin-left: auto; background: rgba(255,255,255,0.3); padding: 2px 8px; border-radius: 20px; font-size: 10px; }
    .nav-link .float-end { margin-left: auto; font-size: 12px; }
    
    .dropdown-menu-custom { 
        background: rgba(255,255,255,0.1); 
        margin-left: 35px; 
        border-radius: 10px; 
        display: none; 
        overflow: hidden;
        transition: all 0.3s ease;
    }
    .dropdown-menu-custom.show { display: block; }
    .dropdown-menu-custom .nav-item { margin: 2px 10px; }
    .dropdown-menu-custom .nav-link { padding: 8px 15px; font-size: 13px; }
    .dropdown-menu-custom .nav-link i { width: 20px; font-size: 14px; }
    
    .nav-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 10px 15px; }
    .nav-title { 
        padding: 10px 20px; 
        font-size: 11px; 
        text-transform: uppercase; 
        letter-spacing: 1px; 
        opacity: 0.6; 
        margin-top: 10px; 
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .nav-title:hover { opacity: 0.9; }
    .nav-title .chevron { font-size: 10px; margin-right: 5px; transition: transform 0.3s; }
    .nav-title .chevron.rotated { transform: rotate(90deg); }
    
    .main-content { margin-left: 280px; padding: 20px; transition: margin-left 0.3s; }
    .main-content.expanded { margin-left: 70px; }
    .menu-toggle { display: none; position: fixed; top: 15px; left: 15px; z-index: 1001; background: #667eea; color: white; border: none; padding: 10px 15px; border-radius: 5px; cursor: pointer; }
    
    /* Sub-menu for Item & Services and Purchase */
    .sub-menu {
        list-style: none;
        padding: 0;
        margin: 0 0 0 20px;
    }
    .sub-menu .nav-item {
        margin: 2px 5px;
    }
    .sub-menu .nav-link {
        padding: 6px 15px;
        font-size: 13px;
    }
    .sub-menu .nav-link i {
        width: 18px;
        font-size: 13px;
    }
    .collapse { display: none; }
    .collapse.show { display: block; }
    
    @media (max-width: 768px) {
        .sidebar { left: -280px; }
        .sidebar.show { left: 0; }
        .main-content { margin-left: 0; padding: 60px 15px 15px 15px; }
        .menu-toggle { display: block; }
    }
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <button class="toggle-btn" onclick="toggleSidebar()">
            <i class="fas fa-chevron-left" id="toggleIcon"></i>
        </button>
        <h3><i class="fas fa-gas-pump"></i> DAFFODIL</h3>
        <p>Fuel Station Management</p>
    </div>
    
    <div class="user-info">
        <div class="user-icon"><i class="fas fa-user-circle"></i></div>
        <div class="user-name"><?php echo $user['full_name'] ?? 'Admin'; ?></div>
        <div class="user-role"><?php echo ucfirst(str_replace('_', ' ', $user['role'] ?? 'super_admin')); ?></div>
    </div>
    
    <ul class="nav-menu">
        <!-- ============================================= -->
        <!-- DASHBOARD -->
        <!-- ============================================= -->
        <li class="nav-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
            <a class="nav-link" href="dashboard.php">
                <i class="fas fa-tachometer-alt"></i>
                <span>Dashboard</span>
            </a>
        </li>
        
        <!-- ============================================= -->
        <!-- POINT OF SALE -->
        <!-- ============================================= -->
        <li class="nav-title" onclick="toggleSection('posSection', 'posChevron')">
            <i class="fas fa-chevron-right chevron" id="posChevron"></i> POINT OF SALE
        </li>
        <div class="dropdown-menu-custom" id="posSection">
            <!-- Fuel POS -->
            <li class="nav-item <?php echo $current_page == 'pos.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="pos.php">
                    <i class="fas fa-shopping-cart"></i> <span>Fuel POS</span>
                    <span class="badge">New</span>
                </a>
            </li>
            <!-- CNG Sales -->
            <li class="nav-item <?php echo $current_page == 'gas_sales.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="gas_sales.php">
                    <i class="fas fa-gas-pump"></i> <span>CNG Sales</span>
                    <span class="badge">New</span>
                </a>
            </li>
            <!-- Item & Services -->
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="collapse" href="#itemSubMenu">
                    <i class="fas fa-boxes"></i> <span>Item & Services</span>
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse" id="itemSubMenu">
                    <ul class="sub-menu">
                        <li class="nav-item <?php echo $current_page == 'item_pos.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="item_pos.php">
                                <i class="fas fa-shopping-cart"></i> POS
                            </a>
                        </li>
                        <li class="nav-item <?php echo $current_page == 'item_management.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="item_management.php">
                                <i class="fas fa-cog"></i> Manage Items
                            </a>
                        </li>
                        <li class="nav-item <?php echo $current_page == 'item_sales_report.php' ? 'active' : ''; ?>">
                            <a class="nav-link" href="item_sales_report.php">
                                <i class="fas fa-chart-bar"></i> Sales Report
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            <!-- Purchase Management -->
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="collapse" href="#purchaseSubMenu">
                    <i class="fas fa-truck"></i> <span>Purchase</span>
                    <i class="fas fa-chevron-down float-end"></i>
                </a>
                <div class="collapse" id="purchaseSubMenu">
                    <ul class="sub-menu">
                        <li class="nav-item <?php echo $current_page == 'item_purchase.php' && !isset($_GET['tab']) ? 'active' : ''; ?>">
                            <a class="nav-link" href="item_purchase.php">
                                <i class="fas fa-plus-circle"></i> New Purchase
                            </a>
                        </li>
                        <li class="nav-item <?php echo isset($_GET['tab']) && $_GET['tab'] == 'history' ? 'active' : ''; ?>">
                            <a class="nav-link" href="item_purchase.php?tab=history">
                                <i class="fas fa-history"></i> Purchase History
                            </a>
                        </li>
                        <li class="nav-item <?php echo isset($_GET['tab']) && $_GET['tab'] == 'stock' ? 'active' : ''; ?>">
                            <a class="nav-link" href="item_purchase.php?tab=stock">
                                <i class="fas fa-warehouse"></i> Stock Overview
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
        </div>
        
        <!-- ============================================= -->
        <!-- SHIFT MANAGEMENT -->
        <!-- ============================================= -->
        <li class="nav-title" onclick="toggleSection('shiftSection', 'shiftChevron')">
            <i class="fas fa-chevron-right chevron" id="shiftChevron"></i> SHIFT MANAGEMENT
        </li>
        <div class="dropdown-menu-custom" id="shiftSection">
            <li class="nav-item <?php echo $current_page == 'shift_closing.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="shift_closing.php">
                    <i class="fas fa-clock"></i> <span>Shift Closing</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'shift_report.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="shift_report.php">
                    <i class="fas fa-chart-bar"></i> <span>Shift Report</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'shift_report.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="shift_closing_report.php">
                    <i class="fas fa-chart-bar"></i> <span>Shift Closing Report</span>
                </a>
            </li>
        </div>
        
        <!-- ============================================= -->
        <!-- INVENTORY -->
        <!-- ============================================= -->
        <li class="nav-title" onclick="toggleSection('inventorySection', 'inventoryChevron')">
            <i class="fas fa-chevron-right chevron" id="inventoryChevron"></i> INVENTORY
        </li>
        <div class="dropdown-menu-custom" id="inventorySection">
            <li class="nav-item <?php echo $current_page == 'tank_settings.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="tank_settings.php">
                    <i class="fas fa-warehouse"></i> <span>Tank Settings</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'nozzle_settings.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="nozzle_settings.php">
                    <i class="fas fa-oil-can"></i> <span>Nozzle Settings</span>
                </a>
            </li>           
            <li class="nav-item <?php echo $current_page == 'fuel_receiving.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="fuel_receiving.php">
                    <i class="fas fa-truck"></i> <span>Fuel Receiving</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'leakage.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="leakage.php">
                    <i class="fas fa-tint"></i> <span>Leakage/Wastage</span>
                </a>
            </li>
        </div>
        
        <!-- ============================================= -->
        <!-- METER READING -->
        <!-- ============================================= -->
        <li class="nav-title" onclick="toggleSection('meterReadingSection', 'meterChevron')">
            <i class="fas fa-chevron-right chevron" id="meterChevron"></i> METER READING
        </li>
        <div class="dropdown-menu-custom" id="meterReadingSection">    
            <li class="nav-item <?php echo $current_page == 'meter_reading.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="meter_reading.php">
                    <i class="fas fa-tachometer-alt"></i> <span>Meter Readings</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'meter_reading_report.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="meter_reading_report.php">
                    <i class="fas fa-chart-bar"></i> <span>Meter Reading Report</span>
                </a>
            </li>     
        </div>
        
        <!-- ============================================= -->
        <!-- ACCOUNTING -->
        <!-- ============================================= -->
        <?php if(in_array($user_role, ['super_admin', 'admin', 'accountant'])): ?>
        <li class="nav-title" onclick="toggleSection('accountingSection', 'accountingChevron')">
            <i class="fas fa-chevron-right chevron" id="accountingChevron"></i> ACCOUNTING
        </li>
        <div class="dropdown-menu-custom" id="accountingSection">
            <li class="nav-item <?php echo $current_page == 'accounting.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="accounting.php">
                    <i class="fas fa-book"></i> <span>Chart of Accounts</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'voucher_entry.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="voucher_entry.php">
                    <i class="fas fa-file-invoice"></i> <span>Voucher Entry</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'advance_management.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="advance_management.php">
                    <i class="fas fa-hand-holding-usd"></i> <span>Advance Management</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'salary_voucher.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="salary_voucher.php">
                    <i class="fas fa-file-invoice-dollar"></i> <span>Salary Voucher</span>
                </a>
            </li>
        </div>
        <?php endif; ?>
        
        <!-- ============================================= -->
        <!-- FINANCIAL REPORTS -->
        <!-- ============================================= -->
        <?php if(in_array($user_role, ['super_admin', 'admin', 'accountant'])): ?>
        <li class="nav-title" onclick="toggleSection('financialSection', 'financialChevron')">
            <i class="fas fa-chevron-right chevron" id="financialChevron"></i> FINANCIAL REPORTS
        </li>
        <div class="dropdown-menu-custom" id="financialSection">
            <li class="nav-item"><a class="nav-link" href="balance_sheet.php"><i class="fas fa-balance-scale"></i> <span>Balance Sheet</span></a></li>
            <li class="nav-item"><a class="nav-link" href="profit_loss.php"><i class="fas fa-chart-line"></i> <span>Profit & Loss</span></a></li>
            <li class="nav-item"><a class="nav-link" href="trial_balance.php"><i class="fas fa-list-ul"></i> <span>Trial Balance</span></a></li>
            <li class="nav-item"><a class="nav-link" href="cash_flow.php"><i class="fas fa-money-bill-wave"></i> <span>Cash Flow</span></a></li>
            <li class="nav-item"><a class="nav-link" href="general_ledger.php"><i class="fas fa-scroll"></i> <span>General Ledger</span></a></li>
        </div>
        <?php endif; ?>
        
        <!-- ============================================= -->
        <!-- CUSTOMER MANAGEMENT -->
        <!-- ============================================= -->
        <li class="nav-title" onclick="toggleSection('customerSection', 'customerChevron')">
            <i class="fas fa-chevron-right chevron" id="customerChevron"></i> CUSTOMER MANAGEMENT
        </li>
        <div class="dropdown-menu-custom" id="customerSection">
            <li class="nav-item <?php echo $current_page == 'customer_ledger.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="customer_ledger.php">
                    <i class="fas fa-users"></i> <span>Customer Ledger</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'customer_payment.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="customer_payment.php">
                    <i class="fas fa-hand-holding-usd"></i> <span>Customer Payment</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'advance_report.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="advance_report.php">
                    <i class="fas fa-file-invoice"></i> <span>Advance Report</span>
                </a>
            </li>
        </div>
        
        <!-- ============================================= -->
        <!-- HUMAN RESOURCES -->
        <!-- ============================================= -->
        <?php if(in_array($user_role, ['super_admin', 'admin', 'hr_officer'])): ?>
        <li class="nav-title" onclick="toggleSection('hrSection', 'hrChevron')">
            <i class="fas fa-chevron-right chevron" id="hrChevron"></i> HUMAN RESOURCES
        </li>
        <div class="dropdown-menu-custom" id="hrSection">
            <li class="nav-item <?php echo $current_page == 'payroll.php' && (!isset($_GET['tab']) || $_GET['tab'] == 'employees') ? 'active' : ''; ?>">
                <a class="nav-link" href="payroll.php?tab=employees">
                    <i class="fas fa-users"></i> <span>Employees</span>
                </a>
            </li>
            <li class="nav-item <?php echo isset($_GET['tab']) && $_GET['tab'] == 'attendance' ? 'active' : ''; ?>">
                <a class="nav-link" href="payroll.php?tab=attendance">
                    <i class="fas fa-clock"></i> <span>Attendance</span>
                </a>
            </li>
            <li class="nav-item <?php echo isset($_GET['tab']) && $_GET['tab'] == 'payroll' ? 'active' : ''; ?>">
                <a class="nav-link" href="payroll.php?tab=payroll">
                    <i class="fas fa-money-check"></i> <span>Payroll</span>
                </a>
            </li>
            <li class="nav-item <?php echo isset($_GET['tab']) && $_GET['tab'] == 'bonus' ? 'active' : ''; ?>">
                <a class="nav-link" href="payroll.php?tab=bonus">
                    <i class="fas fa-gift"></i> <span>Bonus</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page == 'employee_payment.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="employee_payment.php">
                    <i class="fas fa-money-bill-wave"></i> <span>Employee Payments</span>
                </a>
            </li>
        </div>
        <?php endif; ?>
        
        <!-- ============================================= -->
        <!-- RENTAL & INCOME -->
        <!-- ============================================= -->
        <li class="nav-title" onclick="toggleSection('rentalSection', 'rentalChevron')">
            <i class="fas fa-chevron-right chevron" id="rentalChevron"></i> RENTAL & INCOME
        </li>
        <div class="dropdown-menu-custom" id="rentalSection">
            <li class="nav-item <?php echo $current_page == 'rental.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="rental.php">
                    <i class="fas fa-building"></i> <span>Shop Rental</span>
                </a>
            </li>
        </div>    

        <!-- ============================================= -->
        <!-- REPORTS CENTER -->
        <!-- ============================================= -->
        <li class="nav-item <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
            <a class="nav-link" href="reports.php">
                <i class="fas fa-chart-bar"></i> <span>Reports Center</span>
            </a>
        </li>   
       
        <!-- ============================================= -->
        <!-- SETTINGS -->
        <!-- ============================================= -->
        <?php if(in_array($user_role, ['super_admin', 'admin'])): ?>
        <li class="nav-divider"></li>
        <li class="nav-title" onclick="toggleSection('settingsSection', 'settingsChevron')">
            <i class="fas fa-chevron-right chevron" id="settingsChevron"></i> SETTINGS
        </li>
        <div class="dropdown-menu-custom" id="settingsSection">
            <li class="nav-item <?php echo $current_page == 'settings.php' && (!isset($_GET['tab']) || $_GET['tab'] == 'general') ? 'active' : ''; ?>">
                <a class="nav-link" href="settings.php"><i class="fas fa-cogs"></i> <span>System Settings</span></a>
            </li>
            <li class="nav-item <?php echo $current_page == 'tank_settings.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="tank_settings.php"><i class="fas fa-warehouse"></i> <span>Tank Settings</span></a>
            </li>
            <li class="nav-item <?php echo $current_page == 'nozzle_settings.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="nozzle_settings.php"><i class="fas fa-oil-can"></i> <span>Nozzle Settings</span></a>
            </li>
            <li class="nav-item <?php echo $current_page == 'shift_schedule.php' ? 'active' : ''; ?>">
                <a class="nav-link" href="shift_schedule.php"><i class="fas fa-clock"></i> <span>Shift Schedule</span></a>
            </li>
            <?php if($user_role == 'super_admin'): ?>
            <li class="nav-item <?php echo isset($_GET['tab']) && $_GET['tab'] == 'users' ? 'active' : ''; ?>">
                <a class="nav-link" href="settings.php?tab=users"><i class="fas fa-user-plus"></i> <span>Manage Users</span></a>
            </li>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <!-- ============================================= -->
        <!-- LOGOUT -->
        <!-- ============================================= -->
        <li class="nav-divider"></li>
        <li class="nav-item">
            <a class="nav-link" href="logout.php">
                <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
            </a>
        </li>
    </ul>
</div>

<!-- Menu Toggle Button for Mobile -->
<button class="menu-toggle" id="menuToggle" onclick="toggleMobileMenu()">
    <i class="fas fa-bars"></i>
</button>

<script>
    let sidebarCollapsed = false;
    
    function toggleSidebar() {
        sidebarCollapsed = !sidebarCollapsed;
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.querySelector('.main-content');
        const toggleIcon = document.getElementById('toggleIcon');
        
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('expanded');
            toggleIcon.classList.remove('fa-chevron-left');
            toggleIcon.classList.add('fa-chevron-right');
        } else {
            sidebar.classList.remove('collapsed');
            mainContent.classList.remove('expanded');
            toggleIcon.classList.remove('fa-chevron-right');
            toggleIcon.classList.add('fa-chevron-left');
        }
        localStorage.setItem('sidebarCollapsed', sidebarCollapsed);
    }
    
    function toggleSection(sectionId, chevronId) {
        const section = document.getElementById(sectionId);
        const chevron = document.getElementById(chevronId);
        
        if (section) {
            section.classList.toggle('show');
            if (chevron) {
                chevron.classList.toggle('fa-chevron-right');
                chevron.classList.toggle('fa-chevron-down');
            }
            localStorage.setItem(sectionId + 'State', section.classList.contains('show') ? 'open' : 'closed');
        }
    }
    
    function toggleMobileMenu() {
        document.getElementById('sidebar').classList.toggle('show');
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        // Restore sidebar collapsed state
        const savedState = localStorage.getItem('sidebarCollapsed');
        if (savedState === 'true') toggleSidebar();
        
        // Restore section states
        const sections = [
            'posSection', 'shiftSection', 'inventorySection', 'meterReadingSection',
            'accountingSection', 'financialSection', 'customerSection', 
            'hrSection', 'rentalSection', 'settingsSection'
        ];
        
        sections.forEach(function(sectionId) {
            const sectionElement = document.getElementById(sectionId);
            if (sectionElement) {
                const savedSectionState = localStorage.getItem(sectionId + 'State');
                if (savedSectionState === 'open') {
                    sectionElement.classList.add('show');
                    // Find the chevron for this section
                    const chevronId = sectionId.replace('Section', 'Chevron');
                    const chevron = document.getElementById(chevronId);
                    if (chevron) {
                        chevron.classList.remove('fa-chevron-right');
                        chevron.classList.add('fa-chevron-down');
                    }
                }
            }
        });
    });
    
    // Close mobile menu when clicking outside
    document.addEventListener('click', function(event) {
        const sidebar = document.getElementById('sidebar');
        const toggle = document.getElementById('menuToggle');
        if (window.innerWidth <= 768) {
            if (!sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('show');
            }
        }
    });
</script>