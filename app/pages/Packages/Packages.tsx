import React, { Suspense } from "react"
import { Link, RouteComponentProps, Router } from "@reach/router";

import { CurrentPackageContextProvider } from "../../contexts/CurrentPackageContext";

import Tasks from "../Tasks/Tasks";
import EditPackage from "./EditPackage";
import { PackagesContextProvider, usePackagesContext } from "../../contexts/PackagesContext";

const Packages: React.FC<RouteComponentProps> = () => {
    return <PackagesContextProvider>
        <Router basepath="/packages">
            <PackagesIndex path="/" />
            <PackageSubRoute path=":packageId/*" />
        </Router>
    </PackagesContextProvider>
}

const PackageSubRoute: React.FC<RouteComponentProps<{
    packageId: string
}>> = ({ packageId }) => {
    return <CurrentPackageContextProvider currentPackage={packageId}>
        <Router>
            <EditPackage path="edit" />
            <Tasks path="tasks" />
        </Router>
    </CurrentPackageContextProvider>
}

const PackagesIndex: React.FC<RouteComponentProps> = () => {
    return <>
        <Link to="/">Zurück</Link>
        <table className="default">
            <caption>
                Pakete
            </caption>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Letzte Aktualisierung</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <Suspense fallback={<tr>
                    <td colSpan={1000}>Lade Pakete. Bitte warten.</td>
                </tr>}>
                    <RenderPackageTableData />
                </Suspense>
            </tbody>
        </table>
    </>
}

const RenderPackageTableData: React.FC = () => {
    const { packages } = usePackagesContext()

    return <>
        {packages.map((packageItem) => {
            return <tr key={packageItem.getApiId()}>
                <td>
                    <Link
                        to={`${packageItem.getApiId()}/tasks`}
                    >
                        {packageItem.getTitle()}
                    </Link>
                </td>
                <td>{packageItem.getModified().toLocaleString()}</td>
                <td>
                    <Link
                        to={`${packageItem.getApiId()}/edit`}
                    >
                        Bearbeiten &rarr;
                    </Link>
                </td>
            </tr>
        })}
    </>
}

export default Packages
