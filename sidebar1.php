<div :class="{'dark text-white-dark' : $store.app.semidark}">
    <nav x-data="sidebar" class="sidebar fixed bottom-0 top-0 z-50 h-full min-h-screen w-[260px] shadow-[5px_0_25px_0_rgba(94,92,154,0.1)] transition-all duration-300">
        <div class="h-full bg-white dark:bg-[#0e1726]">
            <div class="flex items-center justify-between px-4 py-3">
                <a href="index.html" class="main-logo flex shrink-0 items-center">
                    <img class="ml-[5px] w-8 flex-none" src="assets/images/logo.png" alt="image">
                    <span class="align-middle text-2xl font-semibold ltr:ml-1.5 rtl:mr-1.5 dark:text-white-light lg:inline">Land System</span>
                </a>
                <a href="javascript:;" class="collapse-icon flex h-8 w-8 items-center rounded-full transition duration-300 hover:bg-gray-500/10 rtl:rotate-180 dark:text-white-light dark:hover:bg-dark-light/10" @click="$store.app.toggleSidebar()">
                    <svg class="m-auto h-5 w-5" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M13 19L7 12L13 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                        <path opacity="0.5" d="M16.9998 19L10.9998 12L16.9998 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                    </svg>
                </a>
            </div>
            
            <!-- Superadmin Sidebar Menu -->
            <ul class="perfect-scrollbar relative h-[calc(100vh-80px)] space-y-0.5 overflow-y-auto overflow-x-hidden p-4 py-0 font-semibold" x-data="{ activeDropdown: null }">
                
                <!-- DASHBOARD SECTION -->
                <li class="menu nav-item">
                    <a href="index.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M2 12.2039C2 9.91549 2 8.77128 2.5192 7.82274C3.0384 6.87421 3.98695 6.28551 5.88403 5.10813L7.88403 3.86687C9.88939 2.62229 10.8921 2 12 2C13.1079 2 14.1106 2.62229 16.116 3.86687L18.116 5.10812C20.0131 6.28551 20.9616 6.87421 21.4808 7.82274C22 8.77128 22 9.91549 22 12.2039V13.725C22 17.6258 22 19.5763 20.8284 20.7881C19.6569 22 17.7712 22 14 22H10C6.22876 22 4.34315 22 3.17157 20.7881C2 19.5763 2 17.6258 2 13.725V12.2039Z" fill="currentColor"></path>
                                <path d="M9 17.25C8.58579 17.25 8.25 17.5858 8.25 18C8.25 18.4142 8.58579 18.75 9 18.75H15C15.4142 18.75 15.75 18.4142 15.75 18C15.75 17.5858 15.4142 17.25 15 17.25H9Z" fill="currentColor"></path>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Dashboard</span>
                        </div>
                    </a>
                </li>

                <!-- ADMINISTRATION SECTION -->
                <h2 class="-mx-4 mb-1 flex items-center bg-white-light/30 px-7 py-3 font-extrabold uppercase dark:bg-dark dark:bg-opacity-[0.08]">
                    <span>ADMINISTRATION</span>
                </h2>

                <!-- Users Management -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'users'}" @click="activeDropdown === 'users' ? activeDropdown = null : activeDropdown = 'users'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle opacity="0.5" cx="15" cy="6" r="3" fill="currentColor"></circle>
                                <ellipse opacity="0.5" cx="16" cy="17" rx="5" ry="3" fill="currentColor"></ellipse>
                                <circle cx="9.00098" cy="6" r="4" fill="currentColor"></circle>
                                <ellipse cx="9.00098" cy="17.001" rx="7" ry="4" fill="currentColor"></ellipse>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">User Management</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'users'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'users'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="users.php">All Users</a></li>
                    
                        <li><a href="users-roles-permission.php">User Roles & Permissions</a></li>
                        
                        <li><a href="users-audit-logs.php">Audit Logs</a></li>
                    </ul>
                </li>

                <!-- Parties (Individuals/Organizations) -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'parties'}" @click="activeDropdown === 'parties' ? activeDropdown = null : activeDropdown = 'parties'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle opacity="0.5" cx="12" cy="8" r="4" fill="currentColor"></circle>
                                <path d="M5.33794 17.3206C5.99897 15.7629 7.51645 14.7 9.26814 14.7H14.7319C16.4835 14.7 18.001 15.7629 18.6621 17.3206C19.0518 18.2175 18.6823 19.2587 17.8334 19.7141C15.9571 20.736 13.4137 21.2 12 21.2C10.5863 21.2 8.0429 20.736 6.16659 19.7141C5.31768 19.2587 4.94818 18.2175 5.33794 17.3206Z" fill="currentColor"></path>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Parties</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'parties'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'parties'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="parties.php">All Parties</a></li>
                        <li><a href="parties-individuals.php">Individuals</a></li>
                        <li><a href="parties-organizations.php">Organizations</a></li>
                        <li><a href="parties-create.php">Add New Party</a></li>
                    </ul>
                </li>

                <!-- GEOGRAPHICAL HIERARCHY SECTION -->
                <h2 class="-mx-4 mb-1 flex items-center bg-white-light/30 px-7 py-3 font-extrabold uppercase dark:bg-dark dark:bg-opacity-[0.08]">
                    <span>GEOGRAPHICAL HIERARCHY</span>
                </h2>

                <!-- States -->
                <li class="menu nav-item">
                    <a href="states.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12Z" fill="currentColor"></path>
                                <path d="M5 8.5C5 7.94772 5.44772 7.5 6 7.5H9C9.55228 7.5 10 7.94772 10 8.5C10 9.05228 9.55228 9.5 9 9.5H6C5.44772 9.5 5 9.05228 5 8.5Z" fill="currentColor"></path>
                                <path d="M5 12C5 11.4477 5.44772 11 6 11H12C12.5523 11 13 11.4477 13 12C13 12.5523 12.5523 13 12 13H6C5.44772 13 5 12.5523 5 12Z" fill="currentColor"></path>
                                <path d="M5 15.5C5 14.9477 5.44772 14.5 6 14.5H15C15.5523 14.5 16 14.9477 16 15.5C16 16.0523 15.5523 16.5 15 16.5H6C5.44772 16.5 5 16.0523 5 15.5Z" fill="currentColor"></path>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">States</span>
                        </div>
                    </a>
                </li>

                <!-- Counties -->
                <li class="menu nav-item">
                    <a href="counties.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" fill="currentColor"></path>
                                <path d="M8 7C8 6.44772 8.44772 6 9 6H15C15.5523 6 16 6.44772 16 7C16 7.55228 15.5523 8 15 8H9C8.44772 8 8 7.55228 8 7Z" fill="currentColor"></path>
                                <path d="M8 12C8 11.4477 8.44772 11 9 11H15C15.5523 11 16 11.4477 16 12C16 12.5523 15.5523 13 15 13H9C8.44772 13 8 12.5523 8 12Z" fill="currentColor"></path>
                                <path d="M8 17C8 16.4477 8.44772 16 9 16H12C12.5523 16 13 16.4477 13 17C13 17.5523 12.5523 18 12 18H9C8.44772 18 8 17.5523 8 17Z" fill="currentColor"></path>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Counties</span>
                        </div>
                    </a>
                </li>

                <!-- Payams -->
                <li class="menu nav-item">
                    <a href="payams.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12Z" fill="currentColor"></path>
                                <path d="M7 8C7 7.44772 7.44772 7 8 7H16C16.5523 7 17 7.44772 17 8C17 8.55228 16.5523 9 16 9H8C7.44772 9 7 8.55228 7 8Z" fill="currentColor"></path>
                                <path d="M7 12C7 11.4477 7.44772 11 8 11H16C16.5523 11 17 11.4477 17 12C17 12.5523 16.5523 13 16 13H8C7.44772 13 7 12.5523 7 12Z" fill="currentColor"></path>
                                <path d="M7 16C7 15.4477 7.44772 15 8 15H12C12.5523 15 13 15.4477 13 16C13 16.5523 12.5523 17 12 17H8C7.44772 17 7 16.5523 7 16Z" fill="currentColor"></path>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Payams</span>
                        </div>
                    </a>
                </li>

                <!-- Bomas -->
                <li class="menu nav-item">
                    <a href="bomas.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22Z" fill="currentColor"></path>
                                <path d="M8 9C8 8.44772 8.44772 8 9 8H15C15.5523 8 16 8.44772 16 9C16 9.55228 15.5523 10 15 10H9C8.44772 10 8 9.55228 8 9Z" fill="currentColor"></path>
                                <path d="M8 13C8 12.4477 8.44772 12 9 12H15C15.5523 12 16 12.4477 16 13C16 13.5523 15.5523 14 15 14H9C8.44772 14 8 13.5523 8 13Z" fill="currentColor"></path>
                                <path d="M8 17C8 16.4477 8.44772 16 9 16H12C12.5523 16 13 16.4477 13 17C13 17.5523 12.5523 18 12 18H9C8.44772 18 8 17.5523 8 17Z" fill="currentColor"></path>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Bomas</span>
                        </div>
                    </a>
                </li>

                <!-- LAND MANAGEMENT SECTION -->
                <h2 class="-mx-4 mb-1 flex items-center bg-white-light/30 px-7 py-3 font-extrabold uppercase dark:bg-dark dark:bg-opacity-[0.08]">
                    <span>LAND MANAGEMENT</span>
                </h2>

                <!-- Parcels -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'parcels'}" @click="activeDropdown === 'parcels' ? activeDropdown = null : activeDropdown = 'parcels'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M2 6.5C2 4.29086 3.79086 2.5 6 2.5H18C20.2091 2.5 22 4.29086 22 6.5V17.5C22 19.7091 20.2091 21.5 18 21.5H6C3.79086 21.5 2 19.7091 2 17.5V6.5Z" fill="currentColor"></path>
                                <path d="M7 7.5C7 6.94772 7.44772 6.5 8 6.5H16C16.5523 6.5 17 6.94772 17 7.5C17 8.05228 16.5523 8.5 16 8.5H8C7.44772 8.5 7 8.05228 7 7.5Z" fill="currentColor"></path>
                                <path d="M7 12.5C7 11.9477 7.44772 11.5 8 11.5H16C16.5523 11.5 17 11.9477 17 12.5C17 13.0523 16.5523 13.5 16 13.5H8C7.44772 13.5 7 13.0523 7 12.5Z" fill="currentColor"></path>
                                <path d="M7 17.5C7 16.9477 7.44772 16.5 8 16.5H12C12.5523 16.5 13 16.9477 13 17.5C13 18.0523 12.5523 18.5 12 18.5H8C7.44772 18.5 7 18.0523 7 17.5Z" fill="currentColor"></path>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Parcels</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'parcels'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'parcels'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="parcels.php">All Parcels</a></li>
                        <li><a href="parcels-create.php">Register New Parcel</a></li>
                        <li><a href="parcels-map.php">Parcel Map View</a></li>
                        <li><a href="parcels-history.php">Parcel History</a></li>
                    </ul>
                </li>

                <!-- Titles -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'titles'}" @click="activeDropdown === 'titles' ? activeDropdown = null : activeDropdown = 'titles'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M4 5C4 3.34315 5.34315 2 7 2H17C18.6569 2 20 3.34315 20 5V21L15 18L12 21L9 18L4 21V5Z" fill="currentColor"></path>
                                <path d="M8 8C8 7.44772 8.44772 7 9 7H15C15.5523 7 16 7.44772 16 8C16 8.55228 15.5523 9 15 9H9C8.44772 9 8 8.55228 8 8Z" fill="currentColor"></path>
                                <path d="M8 12C8 11.4477 8.44772 11 9 11H15C15.5523 11 16 11.4477 16 12C16 12.5523 15.5523 13 15 13H9C8.44772 13 8 12.5523 8 12Z" fill="currentColor"></path>
                                <path d="M8 16C8 15.4477 8.44772 15 9 15H12C12.5523 15 13 15.4477 13 16C13 16.5523 12.5523 17 12 17H9C8.44772 17 8 16.5523 8 16Z" fill="currentColor"></path>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Titles</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'titles'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'titles'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="titles.php">All Titles</a></li>
                        <li><a href="titles-issue.php">Issue New Title</a></li>
                        <li><a href="titles-freehold.php">Freehold Titles</a></li>
                        <li><a href="titles-leasehold.php">Leasehold Titles</a></li>
                        <li><a href="titles-customary.php">Customary Titles</a></li>
                    </ul>
                </li>

                <!-- Ownerships -->
                <li class="menu nav-item">
                    <a href="ownerships.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2Z" fill="currentColor"></path>
                                <path d="M12 7C11.4477 7 11 7.44772 11 8V12C11 12.5523 11.4477 13 12 13H16C16.5523 13 17 12.5523 17 12C17 11.4477 16.5523 11 16 11H13V8C13 7.44772 12.5523 7 12 7Z" fill="currentColor"></path>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Ownerships</span>
                        </div>
                    </a>
                </li>

                <!-- Applications & Workflow -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'applications'}" @click="activeDropdown === 'applications' ? activeDropdown = null : activeDropdown = 'applications'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M3 10C3 6.22876 3 4.34315 4.17157 3.17157C5.34315 2 7.22876 2 11 2H13C16.7712 2 18.6569 2 19.8284 3.17157C21 4.34315 21 6.22876 21 10V14C21 17.7712 21 19.6569 19.8284 20.8284C18.6569 22 16.7712 22 13 22H11C7.22876 22 5.34315 22 4.17157 20.8284C3 19.6569 3 17.7712 3 14V10Z" fill="currentColor"></path>
                                <path d="M8 13H16C16.5523 13 17 12.5523 17 12C17 11.4477 16.5523 11 16 11H8C7.44772 11 7 11.4477 7 12C7 12.5523 7.44772 13 8 13Z" fill="currentColor"></path>
                                <path d="M8 17H12C12.5523 17 13 16.5523 13 16C13 15.4477 12.5523 15 12 15H8C7.44772 15 7 15.4477 7 16C7 16.5523 7.44772 17 8 17Z" fill="currentColor"></path>
                                <path d="M8 9H16C16.5523 9 17 8.55228 17 8C17 7.44772 16.5523 7 16 7H8C7.44772 7 7 7.44772 7 8C7 8.55228 7.44772 9 8 9Z" fill="currentColor"></path>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Applications</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'applications'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'applications'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="applications.php">All Applications</a></li>
                        <li><a href="applications-new-title.php">New Title</a></li>
                        <li><a href="applications-transfer.php">Transfer</a></li>
                        <li><a href="applications-subdivision.php">Subdivision</a></li>
                        <li><a href="applications-consolidation.php">Consolidation</a></li>
                        <li><a href="applications-lease.php">Lease</a></li>
                        <li><a href="applications-change-use.php">Change of Use</a></li>
                        <li><a href="workflow-steps.php">Workflow Steps</a></li>
                    </ul>
                </li>

                <!-- Transactions -->
                <li class="menu nav-item">
                    <a href="transactions.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M3 10C3 6.22876 3 4.34315 4.17157 3.17157C5.34315 2 7.22876 2 11 2H13C16.7712 2 18.6569 2 19.8284 3.17157C21 4.34315 21 6.22876 21 10V14C21 17.7712 21 19.6569 19.8284 20.8284C18.6569 22 16.7712 22 13 22H11C7.22876 22 5.34315 22 4.17157 20.8284C3 19.6569 3 17.7712 3 14V10Z" fill="currentColor"></path>
                                <path d="M12 6V18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M9 9L12 6L15 9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                <path d="M15 15L12 18L9 15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Transactions</span>
                        </div>
                    </a>
                </li>

                <!-- FINANCIAL SECTION -->
                <h2 class="-mx-4 mb-1 flex items-center bg-white-light/30 px-7 py-3 font-extrabold uppercase dark:bg-dark dark:bg-opacity-[0.08]">
                    <span>FINANCIAL</span>
                </h2>

                <!-- Payments -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'payments'}" @click="activeDropdown === 'payments' ? activeDropdown = null : activeDropdown = 'payments'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle opacity="0.5" cx="12" cy="12" r="10" fill="currentColor"/>
                                <path d="M16 8H8V16H16V8Z" fill="currentColor"/>
                                <path d="M12 10V14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M10 12H14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Payments</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'payments'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'payments'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="payments.php">All Payments</a></li>
                        <li><a href="payments-pending.php">Pending Payments</a></li>
                        <li><a href="payments-completed.php">Completed Payments</a></li>
                        <li><a href="payments-failed.php">Failed Payments</a></li>
                    </ul>
                </li>

                <!-- Tax Assessments -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'tax'}" @click="activeDropdown === 'tax' ? activeDropdown = null : activeDropdown = 'tax'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M4 10C4 6.22876 4 4.34315 5.17157 3.17157C6.34315 2 8.22876 2 12 2C15.7712 2 17.6569 2 18.8284 3.17157C20 4.34315 20 6.22876 20 10V14C20 17.7712 20 19.6569 18.8284 20.8284C17.6569 22 15.7712 22 12 22C8.22876 22 6.34315 22 5.17157 20.8284C4 19.6569 4 17.7712 4 14V10Z" fill="currentColor"/>
                                <path d="M12 6V18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M8 9H16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M8 15H16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Tax Management</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'tax'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'tax'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="tax-assessments.php">Tax Assessments</a></li>
                        <li><a href="tax-payments.php">Tax Payments</a></li>
                        <li><a href="tax-overdue.php">Overdue Taxes</a></li>
                        <li><a href="tax-reports.php">Tax Reports</a></li>
                    </ul>
                </li>

                <!-- LEGAL SECTION -->
                <h2 class="-mx-4 mb-1 flex items-center bg-white-light/30 px-7 py-3 font-extrabold uppercase dark:bg-dark dark:bg-opacity-[0.08]">
                    <span>LEGAL & DOCUMENTS</span>
                </h2>

                <!-- Disputes -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'disputes'}" @click="activeDropdown === 'disputes' ? activeDropdown = null : activeDropdown = 'disputes'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle opacity="0.5" cx="12" cy="12" r="10" fill="currentColor"/>
                                <path d="M12 7V13" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <circle cx="12" cy="16" r="1" fill="currentColor"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Disputes</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'disputes'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'disputes'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="disputes.php">All Disputes</a></li>
                        <li><a href="disputes-open.php">Open Disputes</a></li>
                        <li><a href="disputes-resolved.php">Resolved Disputes</a></li>
                    </ul>
                </li>

                <!-- Encumbrances -->
                <li class="menu nav-item">
                    <a href="encumbrances.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M12 2C6.47715 2 2 6.47715 2 12C2 17.5228 6.47715 22 12 22C17.5228 22 22 17.5228 22 12C22 6.47715 17.5228 2 12 2Z" fill="currentColor"/>
                                <path d="M12 8V16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M8 12H16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Encumbrances</span>
                        </div>
                    </a>
                </li>

                <!-- Documents -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'documents'}" @click="activeDropdown === 'documents' ? activeDropdown = null : activeDropdown = 'documents'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M4 5C4 3.34315 5.34315 2 7 2H17C18.6569 2 20 3.34315 20 5V21L15 18L12 21L9 18L4 21V5Z" fill="currentColor"/>
                                <path d="M8 8C8 7.44772 8.44772 7 9 7H15C15.5523 7 16 7.44772 16 8C16 8.55228 15.5523 9 15 9H9C8.44772 9 8 8.55228 8 8Z" fill="currentColor"/>
                                <path d="M8 12C8 11.4477 8.44772 11 9 11H15C15.5523 11 16 11.4477 16 12C16 12.5523 15.5523 13 15 13H9C8.44772 13 8 12.5523 8 12Z" fill="currentColor"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Documents</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'documents'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'documents'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="documents.php">All Documents</a></li>
                        <li><a href="documents-parcels.php">Parcel Documents</a></li>
                        <li><a href="documents-titles.php">Title Documents</a></li>
                        <li><a href="documents-parties.php">Party Documents</a></li>
                    </ul>
                </li>

                <!-- SURVEY & ZONING SECTION -->
                <h2 class="-mx-4 mb-1 flex items-center bg-white-light/30 px-7 py-3 font-extrabold uppercase dark:bg-dark dark:bg-opacity-[0.08]">
                    <span>SURVEY & ZONING</span>
                </h2>

                <!-- Zoning -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'zoning'}" @click="activeDropdown === 'zoning' ? activeDropdown = null : activeDropdown = 'zoning'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M3 10C3 6.22876 3 4.34315 4.17157 3.17157C5.34315 2 7.22876 2 11 2H13C16.7712 2 18.6569 2 19.8284 3.17157C21 4.34315 21 6.22876 21 10V14C21 17.7712 21 19.6569 19.8284 20.8284C18.6569 22 16.7712 22 13 22H11C7.22876 22 5.34315 22 4.17157 20.8284C3 19.6569 3 17.7712 3 14V10Z" fill="currentColor"/>
                                <path d="M8 8H16V16H8V8Z" fill="currentColor"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Zoning</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'zoning'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'zoning'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="zoning.php">Zoning Codes</a></li>
                        <li><a href="parcel-zoning-history.php">Parcel Zoning History</a></li>
                    </ul>
                </li>

                <!-- Survey Records -->
                <li class="menu nav-item">
                    <a href="survey-records.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12Z" fill="currentColor"/>
                                <path d="M12 8V12L15 15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Survey Records</span>
                        </div>
                    </a>
                </li>

                <!-- Valuations -->
                <li class="menu nav-item">
                    <a href="valuations.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M3 10H21V16C21 18.8284 21 20.2426 20.1213 21.1213C19.2426 22 17.8284 22 15 22H9C6.17157 22 4.75736 22 3.87868 21.1213C3 20.2426 3 18.8284 3 16V10Z" fill="currentColor"/>
                                <path d="M7 6V5C7 3.34315 8.34315 2 10 2H14C15.6569 2 17 3.34315 17 5V6H19C20.6569 6 22 7.34315 22 9V10H2V9C2 7.34315 3.34315 6 5 6H7Z" fill="currentColor"/>
                                <path d="M12 13V18" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M9 16H15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Valuations</span>
                        </div>
                    </a>
                </li>

                <!-- SYSTEM SECTION -->
                <h2 class="-mx-4 mb-1 flex items-center bg-white-light/30 px-7 py-3 font-extrabold uppercase dark:bg-dark dark:bg-opacity-[0.08]">
                    <span>SYSTEM</span>
                </h2>

                <!-- Audit Logs -->
                <li class="menu nav-item">
                    <a href="audit-logs.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle opacity="0.5" cx="12" cy="12" r="10" fill="currentColor"/>
                                <path d="M12 8V12L15 15" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Audit Logs</span>
                        </div>
                    </a>
                </li>

                <!-- System Settings -->
                <li class="menu nav-item">
                    <a href="system-settings.php" class="nav-link group">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle opacity="0.5" cx="12" cy="12" r="10" fill="currentColor"/>
                                <circle cx="12" cy="12" r="3" fill="currentColor"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">System Settings</span>
                        </div>
                    </a>
                </li>

                <!-- Reports -->
                <li class="menu nav-item">
                    <button type="button" class="nav-link group" :class="{'active' : activeDropdown === 'reports'}" @click="activeDropdown === 'reports' ? activeDropdown = null : activeDropdown = 'reports'">
                        <div class="flex items-center">
                            <svg class="shrink-0 group-hover:!text-primary" width="20" height="20" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path opacity="0.5" d="M3 10C3 6.22876 3 4.34315 4.17157 3.17157C5.34315 2 7.22876 2 11 2H13C16.7712 2 18.6569 2 19.8284 3.17157C21 4.34315 21 6.22876 21 10V14C21 17.7712 21 19.6569 19.8284 20.8284C18.6569 22 16.7712 22 13 22H11C7.22876 22 5.34315 22 4.17157 20.8284C3 19.6569 3 17.7712 3 14V10Z" fill="currentColor"/>
                                <path d="M8 13H16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M8 9H16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                <path d="M8 17H12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                            </svg>
                            <span class="text-black ltr:pl-3 rtl:pr-3 dark:text-[#506690] dark:group-hover:text-white-dark">Reports</span>
                        </div>
                        <div class="rtl:rotate-180" :class="{'!rotate-90' : activeDropdown === 'reports'}">
                            <svg width="16" height="16" viewbox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 5L15 12L9 19" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
                            </svg>
                        </div>
                    </button>
                    <ul x-cloak="" x-show="activeDropdown === 'reports'" x-collapse="" class="sub-menu text-gray-500">
                        <li><a href="reports-parcels.php">Parcels Report</a></li>
                        <li><a href="reports-titles.php">Titles Report</a></li>
                        <li><a href="reports-transactions.php">Transactions Report</a></li>
                        <li><a href="reports-financial.php">Financial Report</a></li>
                        <li><a href="reports-tax.php">Tax Report</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </nav>
</div>